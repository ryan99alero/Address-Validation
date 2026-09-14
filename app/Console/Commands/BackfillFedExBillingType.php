<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\CarrierShipment;
use App\Models\FolderIntegration;
use App\Services\Invoices\BillingType;
use App\Services\Invoices\SmbInvoiceReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-classify FedEx shipment billing_type in place from the invoice CSV's authoritative "Payor" column
 * (Shipper=Prepaid, Recipient=Collect, Third Party=third_party) — the only signal that classifies
 * Express correctly, since Express names the real service in Service Type instead of a payment term.
 *
 * This reads ONLY the Payor + Invoice/Tracking columns and updates ONLY billing_type on existing rows;
 * it does not re-import charges, shipments, or corrections. The tracking number is the join key, scoped
 * to the requested year's FedEx invoices (tracking numbers recycle across years).
 */
class BackfillFedExBillingType extends Command
{
    protected $signature = 'carrier:backfill-fedex-billing-type {--year=2026 : Invoice year to re-classify} {--dry-run : Report without writing}';

    protected $description = 'Set FedEx carrier_shipments.billing_type from the invoice CSV Payor column (no re-import).';

    public function handle(SmbInvoiceReader $smb): int
    {
        $year = (int) $this->option('year');
        $dry = (bool) $this->option('dry-run');
        $fedex = (int) (Carrier::where('slug', 'fedex')->value('id') ?? 0);
        if ($fedex === 0) {
            $this->error('No FedEx carrier configured.');

            return self::FAILURE;
        }

        $folder = FolderIntegration::whereHas('carrier', fn ($q) => $q->where('slug', 'fedex'))
            ->where('connection_type', FolderIntegration::TYPE_SMB)->where('is_active', true)->first();
        if ($folder === null) {
            $this->error('No active FedEx SMB folder integration configured.');

            return self::FAILURE;
        }

        $folder->base_path = $this->parentPath($folder->base_path).'/'.$year;
        $this->info("Scanning SMB //{$folder->smb_host}/{$folder->smb_share}/{$folder->base_path} ...");

        try {
            $files = $smb->listFiles($folder, ['csv'], (bool) $folder->recursive);
        } catch (Throwable $e) {
            $this->error('  SMB list failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('  '.count($files).' CSV file(s).');

        /** @var array<string, string> $map tracking -> billing_type */
        $map = [];
        $filesRead = 0;
        $unreadable = 0;
        foreach ($files as $remote) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'fxbt').'.csv';
            try {
                $smb->download($folder, $remote, $tmp);
                $this->accumulate($tmp, $map);
                $filesRead++;
            } catch (Throwable $e) {
                $this->warn('  ! '.basename((string) $remote).': '.$e->getMessage());
                $unreadable++;
            } finally {
                @unlink($tmp);
            }
        }

        $buckets = $this->bucketByType($map);
        $this->line('  Mapped '.count($map).' trackings from Payor: '
            .'prepaid='.count($buckets[BillingType::PREPAID])
            .', collect='.count($buckets[BillingType::COLLECT])
            .', third_party='.count($buckets[BillingType::THIRD_PARTY]));

        $invoiceIds = DB::table('carrier_invoices')->where('carrier_id', $fedex)
            ->whereYear('invoice_date', $year)->pluck('id')->all();

        $rows = [];
        foreach ($buckets as $type => $trackings) {
            $rows[] = [$type, $this->apply($fedex, $invoiceIds, $trackings, $type, $dry)];
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['CSV files read', $filesRead],
            ['Files unreadable', $unreadable],
        ]);
        $this->table(['billing_type', $dry ? 'Shipment rows that would change' : 'Shipment rows set'], $rows);

        return self::SUCCESS;
    }

    /**
     * Read one FedEx CSV and record the Payor-derived billing type per tracking (first non-null wins;
     * a tracking's rows all carry the same Payor).
     *
     * @param  array<string, string>  $map
     */
    protected function accumulate(string $localPath, array &$map): void
    {
        $h = fopen($localPath, 'r');
        if ($h === false) {
            throw new \RuntimeException('cannot open');
        }
        $header = fgetcsv($h, 0, ',', '"', '');
        if ($header === false) {
            fclose($h);

            return;
        }
        $col = array_flip(array_map('trim', $header));
        $payorCol = $col['Payor'] ?? null;
        $trackCol = $col['Express or Ground Tracking ID'] ?? 9;
        if ($payorCol === null) {
            fclose($h);

            return;
        }

        while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $tracking = $this->normalizeTracking($row[$trackCol] ?? '');
            if ($tracking === '' || isset($map[$tracking])) {
                continue;
            }
            $type = BillingType::fromPayor($row[$payorCol] ?? null);
            if ($type !== null) {
                $map[$tracking] = $type;
            }
        }
        fclose($h);
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, array<int, string>>
     */
    protected function bucketByType(array $map): array
    {
        $buckets = [BillingType::PREPAID => [], BillingType::COLLECT => [], BillingType::THIRD_PARTY => []];
        foreach ($map as $tracking => $type) {
            $buckets[$type][] = $tracking;
        }

        return $buckets;
    }

    /**
     * Set billing_type on the year's FedEx shipments whose tracking is in $trackings. Returns the row
     * count (rows that would change, in dry-run).
     *
     * @param  array<int, int>  $invoiceIds
     * @param  array<int, string>  $trackings
     */
    protected function apply(int $fedex, array $invoiceIds, array $trackings, string $type, bool $dry): int
    {
        if ($trackings === [] || $invoiceIds === []) {
            return 0;
        }

        $affected = 0;
        foreach (array_chunk($trackings, 1000) as $chunk) {
            $base = CarrierShipment::where('carrier_id', $fedex)
                ->whereIn('carrier_invoice_id', $invoiceIds)
                ->whereIn('tracking_number', $chunk);
            if ($dry) {
                $affected += (int) (clone $base)->where(fn ($q) => $q->where('billing_type', '<>', $type)->orWhereNull('billing_type'))->count();
            } else {
                $affected += (int) $base->update(['billing_type' => $type]);
            }
        }

        return $affected;
    }

    protected function normalizeTracking(mixed $raw): string
    {
        return preg_replace('/\s+/', '', (string) $raw) ?? '';
    }

    /**
     * The share path without a trailing 4-digit year, so any year folder can be addressed.
     */
    protected function parentPath(?string $base): string
    {
        return rtrim(preg_replace('#/\d{4}/?$#', '', (string) $base) ?? '', '/');
    }
}
