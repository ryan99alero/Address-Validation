<?php

namespace App\Console\Commands;

use App\Services\Invoices\FedExInvoiceParser;
use Illuminate\Console\Command;

class VerifyFedExParser extends Command
{
    protected $signature = 'invoices:verify-fedex-parser
        {pdf : Path to a FedEx invoice PDF}
        {--csv= : Path to the matching CSV (defaults to same name .CSV)}
        {--show=10 : How many per-tracking mismatches to print}';

    protected $description = 'Verify the FedEx PDF parser against its CSV twin by matching shipments on tracking number';

    public function handle(FedExInvoiceParser $parser): int
    {
        $pdf = $this->argument('pdf');
        $csv = $this->option('csv') ?: preg_replace('/\.pdf$/i', '.CSV', $pdf);

        if (! is_file($pdf) || ! is_file((string) $csv)) {
            $this->error('PDF or CSV not found.');

            return self::FAILURE;
        }

        // PDF: per-tracking total from the parsed ledger.
        $result = $parser->parse($pdf);
        $pdfByTracking = [];
        foreach ($result['shipments'] as $s) {
            if ($s['tracking_id']) {
                $pdfByTracking[$s['tracking_id']] = ($pdfByTracking[$s['tracking_id']] ?? 0) + $s['total_charge'];
            }
        }

        // CSV: per-tracking total = base transport (col 10) + charge pairs (col 107+).
        $csvByTracking = $this->csvTotals((string) $csv);

        $matched = 0;
        $equal = 0;
        $mismatches = [];
        foreach ($pdfByTracking as $tracking => $pdfTotal) {
            if (! isset($csvByTracking[$tracking])) {
                continue;
            }
            $matched++;
            if (abs($pdfTotal - $csvByTracking[$tracking]) <= 0.02) {
                $equal++;
            } else {
                $mismatches[] = [$tracking, round($pdfTotal, 2), round($csvByTracking[$tracking], 2)];
            }
        }

        $this->info('FedEx parser verification — '.basename($pdf));
        $this->table(['Metric', 'Value'], [
            ['PDF shipments reconciled', $result['reconciled']],
            ['PDF shipments skipped', $result['skipped']],
            ['Address corrections parsed', count($result['corrections'])],
            ['Tracking #s in both PDF & CSV', $matched],
            ['Per-tracking totals MATCH', $equal],
            ['Accuracy', $matched ? round($equal / $matched * 100, 1).'%' : 'n/a'],
        ]);

        if ($mismatches !== []) {
            $this->warn('Mismatched tracking totals (PDF vs CSV):');
            $this->table(['Tracking', 'PDF $', 'CSV $'], array_slice($mismatches, 0, (int) $this->option('show')));
        }

        return $matched > 0 && $equal / $matched >= 0.99 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, float>
     */
    protected function csvTotals(string $csv): array
    {
        $handle = fopen($csv, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        $trackingCol = 9;
        $netCol = 11;
        foreach ($header as $i => $name) {
            $name = trim((string) $name);
            if ($name === 'Express or Ground Tracking ID') {
                $trackingCol = $i;
            }
            // The shipment's net (after discounts/surcharges) — the apples-to-
            // apples figure to compare against the PDF's printed Total Charge.
            if ($name === 'Net Charge Amount') {
                $netCol = $i;
            }
        }

        $totals = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $tracking = trim($row[$trackingCol] ?? '');
            if ($tracking === '') {
                continue;
            }
            $net = (float) str_replace([',', '$'], '', $row[$netCol] ?? '0');
            $totals[$tracking] = ($totals[$tracking] ?? 0) + $net;
        }
        fclose($handle);

        return $totals;
    }
}
