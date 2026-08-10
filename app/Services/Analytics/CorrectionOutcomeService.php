<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Correction outcomes on INVOICED shipments — the factual scoreboard for the address engine.
 *
 * Only shipments that (a) we corrected, (b) actually shipped (a carton tracking exists) and (c) have
 * been invoiced (at least one carrier charge on that tracking) are counted, so every number is a
 * fact, not a projection — parsed-but-cancelled / not-yet-shipped work is excluded by construction.
 * Each counted shipment lands in exactly one bucket, and the three sum to the invoiced-corrected
 * total for the period:
 *   prevented — no address/residential carrier fee (a win)
 *   recouped  — got the fee, but we billed it back (a pushed chargeback)
 *   charged   — got the fee and did NOT bill it back (the leak)
 */
class CorrectionOutcomeService
{
    /**
     * Outcome counts bucketed over time (all years → by year, a year → by month, a year+month → by
     * day), keyed off the shipment's ship date.
     *
     * @return Collection<int, object{label:string, prevented:int, recouped:int, charged:int}>
     */
    public function outcomeSeries(?int $year, ?int $month): Collection
    {
        $jobs = $this->correctedJobNumbers();
        if ($jobs === []) {
            return collect();
        }

        [$bucket, $start, $end, $monthOnly] = $this->bucketing($year, $month);

        $inner = DB::table('carton_costs as cc')
            ->whereIn('cc.pace_job_number', $jobs)
            ->whereNotNull('cc.tracking_number')
            ->whereNotNull('cc.ship_date')
            // Invoiced = the tracking carries at least one carrier charge.
            ->whereRaw('EXISTS (SELECT 1 FROM carrier_charges ich WHERE ich.tracking_number = cc.tracking_number)');

        if ($start !== null) {
            $inner->where('cc.ship_date', '>=', $start)->where('cc.ship_date', '<', $end);
        } elseif ($monthOnly !== null) {
            $inner->whereRaw('substr(cc.ship_date, 6, 2) = ?', [sprintf('%02d', $monthOnly)]);
        }

        $feeExists = 'EXISTS (SELECT 1 FROM carrier_charges fch WHERE fch.tracking_number = cc.tracking_number AND '.$this->feeCond('fch').')';
        $recoupExists = "EXISTS (SELECT 1 FROM chargeback_pushes cb WHERE cb.tracking_number = cc.tracking_number AND cb.status = 'pushed' AND ".$this->feeCond('cb').')';

        $inner->selectRaw("{$bucket} AS label,
            CASE WHEN {$feeExists} THEN 1 ELSE 0 END AS has_fee,
            CASE WHEN {$recoupExists} THEN 1 ELSE 0 END AS has_recoup");

        return DB::query()->fromSub($inner, 't')
            ->groupBy('label')
            ->orderBy('label')
            ->selectRaw('label,
                SUM(CASE WHEN has_fee = 0 THEN 1 ELSE 0 END) AS prevented,
                SUM(CASE WHEN has_fee = 1 AND has_recoup = 1 THEN 1 ELSE 0 END) AS recouped,
                SUM(CASE WHEN has_fee = 1 AND has_recoup = 0 THEN 1 ELSE 0 END) AS charged')
            ->get()
            ->map(fn ($r): object => (object) [
                'label' => (string) $r->label,
                'prevented' => (int) $r->prevented,
                'recouped' => (int) $r->recouped,
                'charged' => (int) $r->charged,
            ]);
    }

    /**
     * SQL predicate: an address-correction or residential charge on the given table alias.
     */
    private function feeCond(string $alias): string
    {
        return "({$alias}.charge_category_id = 1 OR {$alias}.driver = 'residential_reclass' OR {$alias}.charge_category_id IN (SELECT id FROM charge_categories WHERE lower(name) LIKE '%residential%'))";
    }

    /**
     * Distinct Pace job numbers we actually corrected (the changes list is non-empty), from the
     * correction log. JSON extraction is driver-branched so it works on MySQL (prod) and SQLite (tests).
     *
     * @return array<int, string>
     */
    private function correctedJobNumbers(): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $job = $sqlite ? "json_extract(metadata, '\$.job_number')" : "json_unquote(json_extract(metadata, '\$.job_number'))";
        $len = $sqlite ? "json_array_length(json_extract(metadata, '\$.changes'))" : "json_length(json_extract(metadata, '\$.changes'))";

        return DB::table('system_logs')
            ->where('type', 'pace_address_correction')
            ->whereRaw("{$job} is not null")
            ->whereRaw("{$len} > 0")
            ->selectRaw("DISTINCT {$job} AS job")
            ->get()
            ->pluck('job')
            ->filter()
            ->map(fn ($v): string => (string) $v)
            ->all();
    }

    /**
     * @return array{0:string, 1:?string, 2:?string, 3:?int} [bucket expr, range start, range end, month-only]
     */
    private function bucketing(?int $year, ?int $month): array
    {
        if ($year === null) {
            return ['substr(cc.ship_date, 1, 4)', null, null, $month];
        }

        $startDate = Carbon::create($year, $month ?? 1, 1)->startOfDay();

        if ($month === null) {
            return ['substr(cc.ship_date, 6, 2)', $startDate->toDateString(), $startDate->copy()->addYear()->toDateString(), null];
        }

        return ['substr(cc.ship_date, 9, 2)', $startDate->toDateString(), $startDate->copy()->addMonth()->toDateString(), null];
    }
}
