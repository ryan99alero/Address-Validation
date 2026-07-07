<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cost-intelligence aggregates for the dashboard. The multi-year TREND views come from the
 * yearly carrier_charge_rollup (fast, full history); the FILTERED period views (a year, or a
 * month within a year, plus its prior-year comparison) are computed live from carrier_charges
 * over an invoice_date range — narrow enough to stay sub-second on the tuned buffer pool.
 * "Accessorial load" = the share of spend that is NOT base transportation. See
 * docs/ShippingCostAnalyticsRoadmap.md.
 */
class CostAnalyticsService
{
    public const CAT_BASE = 13;

    public const CAT_CREDIT = 15;

    public const CAT_ADDRESS_CORRECTION = 1;

    public const CAT_AUDIT_FEE = 10;

    /**
     * Years that have charge data, newest first — for the dashboard year filter.
     *
     * @return array<int, int>
     */
    public function availableYears(): array
    {
        return DB::table('carrier_charge_rollup')
            ->distinct()->orderByDesc('year')->pluck('year')
            ->map(fn ($y): int => (int) $y)->all();
    }

    /**
     * Totals for one period — computed live from carrier_charges. The period is a full year, a
     * single month within a year, all years (year = null), or one month across all years
     * (year = null, month set). Same shape as a yearlyTotals() row.
     */
    public function periodTotals(?int $year, ?int $month = null): object
    {
        $query = DB::table('carrier_charges')->whereNotNull('invoice_date');
        $this->applyPeriod($query, $year, $month);

        $row = $query
            ->selectRaw('
                ROUND(SUM(amount), 2) AS total,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN amount ELSE 0 END), 2) AS base,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN amount ELSE 0 END), 2) AS credit,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN amount ELSE 0 END), 2) AS correction,
                COUNT(DISTINCT CASE WHEN charge_category_id = ? THEN tracking_number END) AS ships
            ', [self::CAT_BASE, self::CAT_CREDIT, self::CAT_ADDRESS_CORRECTION, self::CAT_BASE])
            ->first();

        return $this->shapeTotals($row, $year, $month);
    }

    /**
     * Fee mix (excluding base transport) for one period, largest first — live over the range.
     *
     * @return Collection<int, object{category:string, total:float}>
     */
    public function periodCategoryMix(?int $year, ?int $month = null): Collection
    {
        $query = DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as c', 'c.id', '=', 'cc.charge_category_id')
            ->whereNotNull('cc.invoice_date');
        $this->applyPeriod($query, $year, $month, 'cc.invoice_date');

        return $query
            ->where(fn ($q) => $q->whereNull('cc.charge_category_id')->orWhere('cc.charge_category_id', '!=', self::CAT_BASE))
            ->selectRaw('COALESCE(c.name, ?) AS category, ROUND(SUM(cc.amount), 2) AS total', ['Uncategorized'])
            ->groupBy('category')
            ->havingRaw('SUM(cc.amount) > 0')
            ->orderByDesc('total')
            ->get()
            ->map(function ($r): object {
                $r->total = (float) $r->total;

                return $r;
            });
    }

    /**
     * Constrain a carrier_charges query to the selected period:
     *   year+month → the [start, end) range for that month
     *   year only  → the [start, end) range for that year (uses the invoice_date index)
     *   month only (year = null) → that calendar month across all years (portable substr)
     *   neither    → all time (no date constraint)
     *
     * @param  Builder  $query
     */
    private function applyPeriod($query, ?int $year, ?int $month, string $column = 'invoice_date'): void
    {
        if ($year !== null) {
            [$start, $end] = $this->range($year, $month);
            $query->where($column, '>=', $start)->where($column, '<', $end);
        } elseif ($month !== null) {
            $query->whereRaw("substr($column, 6, 2) = ?", [sprintf('%02d', $month)]);
        }
    }

    /**
     * The invoice_date range [start, end) for a year or a month (uses a range, not YEAR()/MONTH(),
     * so the invoice_date index is used).
     *
     * @return array{0: string, 1: string}
     */
    private function range(int $year, ?int $month): array
    {
        $start = Carbon::create($year, $month ?? 1, 1)->startOfDay();
        $end = $month !== null ? $start->copy()->addMonth() : $start->copy()->addYear();

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Shape a raw totals row into the standard cost object (accessorial/load/cost-per-ship).
     */
    private function shapeTotals(?object $row, ?int $year, ?int $month): object
    {
        $r = (object) [
            'year' => $year,
            'month' => $month,
            'total' => (float) ($row->total ?? 0),
            'base' => (float) ($row->base ?? 0),
            'credit' => (float) ($row->credit ?? 0),
            'correction' => (float) ($row->correction ?? 0),
            'ships' => (int) ($row->ships ?? 0),
        ];
        $r->accessorial = round($r->total - $r->base - $r->credit, 2);
        $r->load_pct = $r->total > 0 ? round($r->accessorial / $r->total * 100, 1) : 0.0;
        $r->cost_per_ship = $r->ships > 0 ? round($r->total / $r->ships, 2) : 0.0;

        return $r;
    }

    /**
     * Per-year totals across all carriers.
     *
     * @return Collection<int, object{year:int, total:float, base:float, accessorial:float, correction:float, ships:int}>
     */
    public function yearlyTotals(): Collection
    {
        return DB::table('carrier_charge_rollup')
            ->selectRaw('
                year,
                ROUND(SUM(total_amount), 2) AS total,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN total_amount ELSE 0 END), 2) AS base,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN total_amount ELSE 0 END), 2) AS credit,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN total_amount ELSE 0 END), 2) AS correction,
                SUM(CASE WHEN charge_category_id = ? THEN distinct_ships ELSE 0 END) AS ships
            ', [self::CAT_BASE, self::CAT_CREDIT, self::CAT_ADDRESS_CORRECTION, self::CAT_BASE])
            ->groupBy('year')
            ->orderBy('year')
            ->get()
            ->map(function ($r): object {
                $r->total = (float) $r->total;
                $r->base = (float) $r->base;
                $r->credit = (float) $r->credit;
                $r->correction = (float) $r->correction;
                // Accessorials = everything that isn't base transportation or a discount/credit
                // (credits are negative, so subtracting them adds their magnitude back out).
                $r->accessorial = round($r->total - $r->base - $r->credit, 2);
                $r->ships = (int) $r->ships;
                $r->load_pct = $r->total > 0 ? round($r->accessorial / $r->total * 100, 1) : 0.0;
                $r->cost_per_ship = $r->ships > 0 ? round($r->total / $r->ships, 2) : 0.0;

                return $r;
            });
    }

    /**
     * The most recent year that has data.
     */
    public function latestYear(): ?object
    {
        return $this->yearlyTotals()->last();
    }

    /**
     * Per-year totals for a single calendar month across all years (e.g. every June), computed live
     * from carrier_charges — the "is this month improving year over year?" series for the trend
     * charts when a month is selected. Same row shape as yearlyTotals().
     *
     * @return Collection<int, object{year:int, total:float, base:float, credit:float, correction:float, accessorial:float, ships:int, load_pct:float, cost_per_ship:float}>
     */
    public function yearlyTotalsForMonth(int $month): Collection
    {
        // substr on the ISO date (YYYY-MM-DD) rather than MONTH()/YEAR() — portable across MySQL
        // (prod) and the SQLite test database, which has no month/year date functions.
        return DB::table('carrier_charges')
            ->whereNotNull('invoice_date')
            ->whereRaw('substr(invoice_date, 6, 2) = ?', [sprintf('%02d', $month)])
            ->groupByRaw('substr(invoice_date, 1, 4)')
            ->orderByRaw('substr(invoice_date, 1, 4)')
            ->selectRaw('
                substr(invoice_date, 1, 4) AS year,
                ROUND(SUM(amount), 2) AS total,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN amount ELSE 0 END), 2) AS base,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN amount ELSE 0 END), 2) AS credit,
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN amount ELSE 0 END), 2) AS correction,
                COUNT(DISTINCT CASE WHEN charge_category_id = ? THEN tracking_number END) AS ships
            ', [self::CAT_BASE, self::CAT_CREDIT, self::CAT_ADDRESS_CORRECTION, self::CAT_BASE])
            ->get()
            ->map(fn ($r): object => $this->shapeTotals($r, (int) $r->year, $month));
    }

    /**
     * Spend by canonical category for a given year (or all years when null), largest first —
     * the fee-mix breakdown. Base transportation is excluded so accessorials stand out.
     *
     * @return Collection<int, object{category:string, total:float}>
     */
    public function categoryMix(?int $year = null): Collection
    {
        return DB::table('carrier_charge_rollup as r')
            ->leftJoin('charge_categories as c', 'c.id', '=', 'r.charge_category_id')
            ->when($year !== null, fn ($q) => $q->where('r.year', $year))
            ->where(fn ($q) => $q->whereNull('r.charge_category_id')->orWhere('r.charge_category_id', '!=', self::CAT_BASE))
            ->selectRaw('COALESCE(c.name, ?) AS category, ROUND(SUM(r.total_amount), 2) AS total', ['Uncategorized'])
            ->groupBy('category')
            ->havingRaw('SUM(r.total_amount) > 0')
            ->orderByDesc('total')
            ->get()
            ->map(function ($r): object {
                $r->total = (float) $r->total;

                return $r;
            });
    }
}
