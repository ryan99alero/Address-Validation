<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The address-engine FUNNEL over time, on invoiced shipments only — the factual pipeline, broken out
 * by sub-period (all years → by year, a year → by month, a year+month → by day).
 *
 * We can only tie a correction to a real shipment through the Pace job number
 * (correction.job_number → carton_costs.pace_job_number → tracking → carrier charges), so this is
 * the Pace Connect path. Only shipments that shipped (a carton tracking) AND were invoiced (≥1
 * carrier charge) are counted, so every number is a fact. Within each bucket each shipment is scored
 * into the funnel stages (nested subsets — the widget draws them as grouped, never stacked, bars):
 *   processed      — we ran the address (the job has a Pace correction event)
 *   fixed          — we changed the address
 *   avoided        — fixed, and no address/residential fee landed (the win)
 *   charged_fixed  — fixed, but got the fee anyway (fix didn't stick / too late)
 *   charged_nofix  — we said no fix was needed, but it got the fee (a miss / disputable)
 *   billed_back    — got the fee and we billed it back to the job (recovered)
 */
class CorrectionOutcomeService
{
    /**
     * @return Collection<int, object{label:string, processed:int, fixed:int, avoided:int, charged_fixed:int, charged_nofix:int, billed_back:int}>
     */
    public function funnelSeries(?int $year, ?int $month): Collection
    {
        $allJobs = $this->processedJobNumbers(changedOnly: false);
        if ($allJobs === []) {
            return collect();
        }
        $fixed = array_flip($this->processedJobNumbers(changedOnly: true));

        [$bucket, $start, $end, $monthOnly] = $this->bucketing($year, $month);

        $query = DB::table('carton_costs as cc')
            ->whereIn('cc.pace_job_number', $allJobs)
            ->whereNotNull('cc.tracking_number')
            ->whereNotNull('cc.ship_date')
            // Invoiced = the tracking carries at least one carrier charge.
            ->whereRaw('EXISTS (SELECT 1 FROM carrier_charges ich WHERE ich.tracking_number = cc.tracking_number)');

        if ($start !== null) {
            $query->where('cc.ship_date', '>=', $start)->where('cc.ship_date', '<', $end);
        } elseif ($monthOnly !== null) {
            $query->whereRaw('substr(cc.ship_date, 6, 2) = ?', [sprintf('%02d', $monthOnly)]);
        }

        $feeExists = 'EXISTS (SELECT 1 FROM carrier_charges fch WHERE fch.tracking_number = cc.tracking_number AND '.$this->feeCond('fch').')';
        $recoupExists = "EXISTS (SELECT 1 FROM chargeback_pushes cb WHERE cb.tracking_number = cc.tracking_number AND cb.status = 'pushed' AND ".$this->feeCond('cb').')';

        $rows = $query->selectRaw("{$bucket} AS label, cc.pace_job_number AS job,
            CASE WHEN {$feeExists} THEN 1 ELSE 0 END AS has_fee,
            CASE WHEN {$recoupExists} THEN 1 ELSE 0 END AS recouped")
            ->get();

        $buckets = [];
        foreach ($rows as $row) {
            $label = (string) $row->label;
            $funnel = $buckets[$label] ??= $this->emptyFunnel($label);

            $funnel->processed++;
            $wasFixed = isset($fixed[$row->job]);
            if ($wasFixed) {
                $funnel->fixed++;
            }
            if ($row->has_fee) {
                $wasFixed ? $funnel->charged_fixed++ : $funnel->charged_nofix++;
                if ($row->recouped) {
                    $funnel->billed_back++;
                }
            } elseif ($wasFixed) {
                $funnel->avoided++;
            }
        }

        ksort($buckets);

        return collect($buckets)->values();
    }

    private function emptyFunnel(string $label): object
    {
        return (object) ['label' => $label, 'processed' => 0, 'fixed' => 0, 'avoided' => 0, 'charged_fixed' => 0, 'charged_nofix' => 0, 'billed_back' => 0];
    }

    /**
     * SQL predicate: an address-correction or residential charge on the given table alias.
     */
    private function feeCond(string $alias): string
    {
        return "({$alias}.charge_category_id = 1 OR {$alias}.driver = 'residential_reclass' OR {$alias}.charge_category_id IN (SELECT id FROM charge_categories WHERE lower(name) LIKE '%residential%'))";
    }

    /**
     * Distinct Pace job numbers we processed. changedOnly = only the ones we actually corrected
     * (non-empty changes); otherwise every job the engine looked at (change or no-change). JSON
     * extraction is driver-branched so it works on MySQL (prod) and SQLite (tests).
     *
     * @return array<int, string>
     */
    private function processedJobNumbers(bool $changedOnly): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $job = $sqlite ? "json_extract(metadata, '\$.job_number')" : "json_unquote(json_extract(metadata, '\$.job_number'))";

        $query = DB::table('system_logs')
            ->where('type', 'pace_address_correction')
            ->whereRaw("{$job} is not null");

        if ($changedOnly) {
            $len = $sqlite ? "json_array_length(json_extract(metadata, '\$.changes'))" : "json_length(json_extract(metadata, '\$.changes'))";
            $query->whereRaw("{$len} > 0");
        }

        return $query->selectRaw("DISTINCT {$job} AS job")
            ->get()
            ->pluck('job')
            ->filter()
            ->map(fn ($v): string => (string) $v)
            ->all();
    }

    /**
     * @return array{0:string, 1:?string, 2:?string, 3:?int} [bucket expr, ship_date range start, end, month-only]
     */
    private function bucketing(?int $year, ?int $month): array
    {
        if ($year === null) {
            return ['substr(cc.ship_date, 1, 4)', null, null, $month];
        }

        $start = Carbon::create($year, $month ?? 1, 1)->startOfDay();

        if ($month === null) {
            return ['substr(cc.ship_date, 6, 2)', $start->toDateString(), $start->copy()->addYear()->toDateString(), null];
        }

        return ['substr(cc.ship_date, 9, 2)', $start->toDateString(), $start->copy()->addMonth()->toDateString(), null];
    }
}
