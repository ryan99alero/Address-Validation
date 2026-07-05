<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cost-intelligence aggregates for the dashboard, sourced from the yearly
 * carrier_charge_rollup (carrier × category × year). "Accessorial load" = the share of
 * spend that is NOT base transportation — the metric that reveals where cost really comes
 * from. See docs/ShippingCostAnalyticsRoadmap.md.
 */
class CostAnalyticsService
{
    public const CAT_BASE = 13;

    public const CAT_CREDIT = 15;

    public const CAT_ADDRESS_CORRECTION = 1;

    public const CAT_AUDIT_FEE = 10;

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
                ROUND(SUM(CASE WHEN charge_category_id = ? THEN total_amount ELSE 0 END), 2) AS correction,
                SUM(CASE WHEN charge_category_id = ? THEN distinct_ships ELSE 0 END) AS ships
            ', [self::CAT_BASE, self::CAT_ADDRESS_CORRECTION, self::CAT_BASE])
            ->groupBy('year')
            ->orderBy('year')
            ->get()
            ->map(function ($r): object {
                $r->total = (float) $r->total;
                $r->base = (float) $r->base;
                $r->correction = (float) $r->correction;
                $r->accessorial = round($r->total - $r->base, 2);
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
