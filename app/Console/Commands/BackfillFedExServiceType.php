<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\CarrierShipment;
use App\Models\FolderIntegration;
use App\Services\Invoices\FedExInvoiceParser;
use App\Services\Invoices\SmbInvoiceReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rescan FedEx invoice PDFs on the SMB share and backfill the per-shipment service (ship method) the
 * old parser dropped — Express digit-initial services recorded NULL, Ground shipments recorded the
 * payment term ("Ppd, Domestic"). See FedExInvoiceParser::extractServiceType(). This only UPDATES
 * carrier_shipments.service where it is still missing or a payment term, so it never re-imports
 * charges, never double-counts, and is safe to re-run. The FedEx SMB folder integration is scoped to
 * one year (e.g. .../FedEx Invoices/2026); we address sibling year folders by swapping the trailing
 * year on its base_path.
 */
class BackfillFedExServiceType extends Command
{
    protected $signature = 'fedex:backfill-service
        {--years=2025,2026 : Comma-separated year folders under the FedEx SMB path to rescan}
        {--limit=0 : Max PDF files to process per year (0 = all)}
        {--dry-run : Parse and report, but write no service updates}';

    protected $description = 'Rescan FedEx SMB invoice PDFs and backfill carrier_shipments.service where it is missing or a payment term (Ppd/Bill/Collect).';

    public function handle(SmbInvoiceReader $smb, FedExInvoiceParser $parser): int
    {
        $carrier = Carrier::where('slug', 'fedex')->first();
        if ($carrier === null) {
            $this->error('FedEx carrier not found.');

            return self::FAILURE;
        }

        $folder = FolderIntegration::where('carrier_id', $carrier->id)
            ->where('connection_type', FolderIntegration::TYPE_SMB)
            ->orderByDesc('is_active')
            ->first();
        if ($folder === null) {
            $this->error('No FedEx SMB folder integration configured.');

            return self::FAILURE;
        }

        $parent = $this->parentPath($folder->base_path);
        $years = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('years')))));
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $totals = ['files' => 0, 'parsed' => 0, 'updated' => 0, 'unresolved' => 0, 'missing_rows' => 0];
        $unresolvedSamples = [];

        foreach ($years as $year) {
            $folder->base_path = $parent === '' ? $year : $parent.'/'.$year;
            $this->info("Scanning //{$folder->smb_host}/{$folder->smb_share}/{$folder->base_path} ...");

            try {
                $files = $smb->listFiles($folder, ['pdf'], (bool) $folder->recursive);
            } catch (Throwable $e) {
                $this->error('  Could not list folder: '.$e->getMessage());

                continue;
            }
            if ($limit > 0) {
                $files = array_slice($files, 0, $limit);
            }
            $this->info('  '.count($files).' PDF file(s).');

            foreach ($files as $file) {
                $rows = $this->parseFile($smb, $parser, $folder, $file);
                if ($rows === null) {
                    continue;
                }
                $totals['files']++;

                $result = $this->applyShipmentServices($carrier->id, $rows, $dryRun);
                $totals['parsed'] += $result['parsed'];
                $totals['updated'] += $result['updated'];
                $totals['unresolved'] += $result['unresolved'];
                $totals['missing_rows'] += $result['missing_rows'];
                foreach ($result['unresolved_samples'] as $tracking) {
                    if (count($unresolvedSamples) < 40) {
                        $unresolvedSamples[] = $tracking.'  ('.basename($file).')';
                    }
                }
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['PDF files parsed', $totals['files']],
            ['Shipments parsed', $totals['parsed']],
            [$dryRun ? 'Would update (dry run)' : 'Service rows updated', $totals['updated']],
            ['Unresolved service (lookup failed)', $totals['unresolved']],
            ['Parsed trackings with no shipment row', $totals['missing_rows']],
        ]);

        if ($unresolvedSamples !== []) {
            $this->newLine();
            $this->warn('Trackings whose service still could not be resolved (sample):');
            foreach ($unresolvedSamples as $s) {
                $this->line('  '.$s);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Download + parse one PDF into [['tracking' => string, 'service' => ?string], ...].
     * Returns null on any download/parse failure (logged as a warning, skipped).
     *
     * @return array<int, array{tracking: string, service: ?string}>|null
     */
    protected function parseFile(SmbInvoiceReader $smb, FedExInvoiceParser $parser, FolderIntegration $folder, string $file): ?array
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'fxsvc_').'.pdf';
        try {
            $smb->download($folder, $file, $temp);
            $parsed = $parser->parseStructured($temp);
        } catch (Throwable $e) {
            $this->warn('  ! '.basename($file).': '.$e->getMessage());

            return null;
        } finally {
            @unlink($temp);
        }

        $rows = [];
        foreach ($parsed['invoices'] as $invoice) {
            foreach ($invoice['shipments'] as $shipment) {
                $tracking = trim((string) ($shipment['tracking_id'] ?? ''));
                if ($tracking === '') {
                    continue;
                }
                $service = $shipment['service_type'] ?? null;
                $rows[] = ['tracking' => $tracking, 'service' => $service !== null ? trim((string) $service) : null];
            }
        }

        return $rows;
    }

    /**
     * Apply parsed services to carrier_shipments: update rows still missing a real service, count
     * lookup failures (parser still couldn't name a service), and note parsed trackings we hold no
     * shipment row for. Only rows whose service is NULL or a payment term are touched — idempotent.
     *
     * @param  array<int, array{tracking: string, service: ?string}>  $rows
     * @return array{parsed: int, updated: int, unresolved: int, missing_rows: int, unresolved_samples: array<int, string>}
     */
    public function applyShipmentServices(int $carrierId, array $rows, bool $dryRun): array
    {
        $out = ['parsed' => 0, 'updated' => 0, 'unresolved' => 0, 'missing_rows' => 0, 'unresolved_samples' => []];

        foreach ($rows as $row) {
            $out['parsed']++;
            $service = $row['service'];

            if ($service === null || $service === '' || $this->isPaymentTerm($service)) {
                $out['unresolved']++;
                $out['unresolved_samples'][] = $row['tracking'].($service !== null && $service !== '' ? " → {$service}" : ' → (none)');

                continue;
            }

            $query = CarrierShipment::query()
                ->where('carrier_id', $carrierId)
                ->where('tracking_number', $row['tracking'])
                ->where(function ($w): void {
                    $w->whereNull('service')
                        ->orWhere('service', 'like', 'Ppd%')
                        ->orWhere('service', 'like', 'Bill%')
                        ->orWhere('service', 'like', 'Collect%');
                });

            if ($dryRun) {
                $out['updated'] += $query->count();

                continue;
            }

            $affected = $query->update(['service' => $service]);
            $out['updated'] += $affected;

            if ($affected === 0
                && ! CarrierShipment::where('carrier_id', $carrierId)->where('tracking_number', $row['tracking'])->exists()) {
                $out['missing_rows']++;
            }
        }

        return $out;
    }

    /**
     * The FedEx SMB path is scoped to a single year (.../FedEx Invoices/2026). Strip a trailing
     * 4-digit year so callers can address sibling year folders (.../2025).
     */
    protected function parentPath(?string $base): string
    {
        $base = trim((string) $base, '/');
        if (preg_match('#^(.*)/\d{4}$#', $base, $m)) {
            return $m[1];
        }

        return $base;
    }

    protected function isPaymentTerm(string $service): bool
    {
        return (bool) preg_match('/^(Ppd|Bill|Collect)/i', $service);
    }
}
