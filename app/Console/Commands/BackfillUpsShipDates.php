<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Services\Invoices\UpsPdfChargeParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
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
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-parse archived UPS invoice PDFs and fill missing ship_date on shipments/charges/lines.';

    public function handle(): int
    {
        $upsId = (int) (Carrier::where('slug', 'ups')->value('id') ?? 0);
        if ($upsId === 0) {
            $this->error('UPS carrier not found.');

            return self::FAILURE;
        }

        $year = (int) $this->option('year');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        $files = DB::table('carrier_invoices as i')
            ->join('carrier_shipments as s', 's.carrier_invoice_id', '=', 'i.id')
            ->where('i.carrier_id', $upsId)->where('s.source_type', 'pdf')->whereNull('s.ship_date')
            ->whereYear('i.invoice_date', $year)
            ->whereNotNull('i.archived_path')->where('i.archived_path', '<>', '')
            ->distinct()->pluck('i.archived_path');

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
                $parsed = (new UpsPdfChargeParser)->parse((new Parser)->parseFile($path)->getText());
            } catch (Throwable $e) {
                $this->warn('  ! '.basename((string) $rel).': '.$e->getMessage());
                $totals['unreadable']++;

                continue;
            }

            $map = [];
            foreach ($parsed['shipments'] as $s) {
                $tracking = trim((string) ($s['tracking_number'] ?? ''));
                $date = $s['ship_date'] ?? null;
                if ($tracking !== '' && $date) {
                    $map[$tracking] = $date;
                }
            }

            $result = $this->applyShipDates($upsId, $map, $dry);
            $totals['files']++;
            $totals['shipments'] += $result['shipments'];
            $totals['charges'] += $result['charges'];
            $totals['lines'] += $result['lines'];
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['PDF files re-parsed', $totals['files']],
            ['Files unreadable/missing', $totals['unreadable']],
            [$dry ? 'Shipments that would be dated' : 'Shipments dated', $totals['shipments']],
            [$dry ? 'Charges that would be dated' : 'Charges dated', $totals['charges']],
            [$dry ? 'Invoice lines that would be dated' : 'Invoice lines dated', $totals['lines']],
        ]);

        return self::SUCCESS;
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
     * Resolve an archived relative path to a readable absolute path (the 'local' disk, then a couple
     * of known fallbacks), or null if the file can't be found.
     */
    private function resolvePath(string $rel): ?string
    {
        $candidates = [
            Storage::disk('local')->path($rel),
            storage_path('app/'.$rel),
            storage_path('app/private/'.ltrim($rel, '/')),
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        return null;
    }
}
