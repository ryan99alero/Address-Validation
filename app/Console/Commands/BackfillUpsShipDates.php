<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\FolderIntegration;
use App\Services\Invoices\PdfTextExtractor;
use App\Services\Invoices\SmbInvoiceReader;
use App\Services\Invoices\UpsPdfChargeParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backfill missing ship_date on UPS PDF-imported rows. Older imports ran before the UPS PDF date fix
 * (invoice-date label had no trailing space -> year null; and the pickup date is printed once per
 * group), so ship_date came back null. This re-parses each archived UPS PDF with the corrected parser
 * and fills ONLY the null ship_dates on carrier_shipments / carrier_charges / carrier_invoice_lines.
 * It does NOT re-import charges, so there is no dedup churn and no Re-Correction re-raise.
 */
class BackfillUpsShipDates extends Command
{
    protected $signature = 'ups:backfill-ship-dates
        {--year=2026 : Invoice year to backfill}
        {--limit=0 : Max PDF files to process (0 = all)}
        {--smb : Re-parse straight from the UPS SMB share (reaches invoices with no on-disk copy / no source_file)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-parse UPS invoice PDFs and fill missing ship_date on shipments/charges/lines.';

    public function handle(SmbInvoiceReader $smb): int
    {
        $upsId = (int) (Carrier::where('slug', 'ups')->value('id') ?? 0);
        if ($upsId === 0) {
            $this->error('UPS carrier not found.');

            return self::FAILURE;
        }

        $year = (int) $this->option('year');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        if ($this->option('smb')) {
            return $this->handleSmb($smb, $upsId, $year, $limit, $dry);
        }

        // Key on the shipment's source_file (the real batch filename) rather than archived_path: most
        // UPS PDFs aren't mail-archived, but the importer's extracted copy survives in the work dir.
        $files = DB::table('carrier_invoices as i')
            ->join('carrier_shipments as s', 's.carrier_invoice_id', '=', 'i.id')
            ->where('i.carrier_id', $upsId)->where('s.source_type', 'pdf')->whereNull('s.ship_date')
            ->whereYear('i.invoice_date', $year)
            ->whereNotNull('s.source_file')->where('s.source_file', '<>', '')
            ->distinct()->pluck('s.source_file');

        if ($limit > 0) {
            $files = $files->take($limit);
        }
        $this->info($files->count()." archived UPS PDF file(s) with missing dates in {$year}.");

        $totals = ['files' => 0, 'unreadable' => 0, 'shipments' => 0, 'charges' => 0, 'lines' => 0];

        foreach ($files as $rel) {
            $path = $this->resolvePath((string) $rel);
            if ($path === null) {
                $totals['unreadable']++;

                continue;
            }

            try {
                $map = $this->parseToMap($path);
            } catch (Throwable $e) {
                $this->warn('  ! '.basename((string) $rel).': '.$e->getMessage());
                $totals['unreadable']++;

                continue;
            }

            $result = $this->applyShipDates($upsId, $map, $dry);
            $totals['files']++;
            $totals['shipments'] += $result['shipments'];
            $totals['charges'] += $result['charges'];
            $totals['lines'] += $result['lines'];
        }

        $this->reportTotals($totals, $dry);

        return self::SUCCESS;
    }

    /**
     * Re-parse from the UPS SMB share for a year — reaches invoices with no on-disk copy or no
     * source_file. The tracking number is the join key, so no invoice-to-file matching is needed.
     */
    protected function handleSmb(SmbInvoiceReader $smb, int $upsId, int $year, int $limit, bool $dry): int
    {
        $folder = FolderIntegration::whereHas('carrier', fn ($q) => $q->where('slug', 'ups'))
            ->where('connection_type', FolderIntegration::TYPE_SMB)->first();
        if ($folder === null) {
            $this->error('No UPS SMB folder integration configured.');

            return self::FAILURE;
        }

        $folder->base_path = $this->parentPath($folder->base_path).'/'.$year;
        $this->info("Scanning SMB //{$folder->smb_host}/{$folder->smb_share}/{$folder->base_path} ...");

        try {
            $files = $smb->listFiles($folder, ['pdf'], (bool) $folder->recursive);
        } catch (Throwable $e) {
            $this->error('  SMB list failed: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }
        $this->info('  '.count($files).' PDF file(s).');

        $totals = ['files' => 0, 'unreadable' => 0, 'shipments' => 0, 'charges' => 0, 'lines' => 0];

        foreach ($files as $remote) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'upsbf').'.pdf';
            try {
                $smb->download($folder, $remote, $tmp);
                $map = $this->parseToMap($tmp);
            } catch (Throwable $e) {
                $this->warn('  ! '.basename($remote).': '.$e->getMessage());
                $totals['unreadable']++;
                @unlink($tmp);

                continue;
            }
            @unlink($tmp);

            $result = $this->applyShipDates($upsId, $map, $dry);
            $totals['files']++;
            $totals['shipments'] += $result['shipments'];
            $totals['charges'] += $result['charges'];
            $totals['lines'] += $result['lines'];
            if ($result['shipments'] > 0) {
                $this->line('  '.basename($remote).': +'.$result['shipments'].' shipments');
            }
        }

        $this->reportTotals($totals, $dry);

        return self::SUCCESS;
    }

    /**
     * Parse a UPS PDF (via the same PdfTextExtractor importUpsPdf uses — raw smalot under-extracts
     * 150+ page invoices) into a tracking -> ship_date map (non-null dates only).
     *
     * @return array<string, string>
     */
    protected function parseToMap(string $localPath): array
    {
        $parsed = (new UpsPdfChargeParser)->parse((new PdfTextExtractor)->extractFile($localPath));

        $map = [];
        foreach ($parsed['shipments'] as $s) {
            $tracking = trim((string) ($s['tracking_number'] ?? ''));
            $date = $s['ship_date'] ?? null;
            if ($tracking !== '' && $date) {
                $map[$tracking] = $date;
            }
        }

        return $map;
    }

    /**
     * @param  array{files: int, unreadable: int, shipments: int, charges: int, lines: int}  $totals
     */
    protected function reportTotals(array $totals, bool $dry): void
    {
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['PDF files re-parsed', $totals['files']],
            ['Files unreadable/missing', $totals['unreadable']],
            [$dry ? 'Shipments that would be dated' : 'Shipments dated', $totals['shipments']],
            [$dry ? 'Charges that would be dated' : 'Charges dated', $totals['charges']],
            [$dry ? 'Invoice lines that would be dated' : 'Invoice lines dated', $totals['lines']],
        ]);
    }

    /**
     * Fill null ship_date on UPS PDF shipments/charges (and any invoice lines) for the parsed
     * tracking -> date map. Only touches rows whose ship_date IS NULL, so it is idempotent.
     *
     * @param  array<string, string>  $trackingToDate
     * @return array{shipments: int, charges: int, lines: int}
     */
    public function applyShipDates(int $upsId, array $trackingToDate, bool $dryRun): array
    {
        $out = ['shipments' => 0, 'charges' => 0, 'lines' => 0];

        foreach ($trackingToDate as $tracking => $date) {
            $shipQ = DB::table('carrier_shipments')->where('carrier_id', $upsId)
                ->where('tracking_number', $tracking)->where('source_type', 'pdf')->whereNull('ship_date');
            $chargeQ = DB::table('carrier_charges')->where('carrier_id', $upsId)
                ->where('tracking_number', $tracking)->where('source_type', 'pdf')->whereNull('ship_date');
            $lineQ = DB::table('carrier_invoice_lines')->where('tracking_number', $tracking)->whereNull('ship_date');

            if ($dryRun) {
                $out['shipments'] += $shipQ->count();
                $out['charges'] += $chargeQ->count();
                $out['lines'] += $lineQ->count();

                continue;
            }

            $out['shipments'] += $shipQ->update(['ship_date' => $date]);
            $out['charges'] += $chargeQ->update(['ship_date' => $date]);
            $out['lines'] += $lineQ->update(['ship_date' => $date]);
        }

        return $out;
    }

    /**
     * Resolve a source_file (or archived relative path) to a readable absolute PDF. Tries the archived
     * copy, then globs the importer's work/extracted dirs and the processed archive for the basename.
     */
    private function resolvePath(string $ref): ?string
    {
        $base = basename($ref);

        // Direct paths (when $ref is an archived relative path).
        foreach ([Storage::disk('local')->path($ref), storage_path('app/'.$ref)] as $direct) {
            if (! str_contains($direct, '*') && is_file($direct)) {
                return $direct;
            }
        }

        // Glob the known locations for a bare filename.
        $patterns = [
            storage_path('app/invoices/work/*/*/extracted/'.$base),
            storage_path('app/invoices/work/*/*/'.$base),
            storage_path('app/private/invoices/processed/UPS/*/*/'.$base),
        ];
        foreach ($patterns as $pattern) {
            $hits = glob($pattern) ?: [];
            if ($hits !== [] && is_file($hits[0])) {
                return $hits[0];
            }
        }

        return null;
    }
}
