<?php

namespace App\Services\Recoup;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer recoup: what the carrier actually billed for a package, minus what Process Shipper
 * quoted/recorded at ship time (the Pace carton's ship_cost), is the surprise the customer
 * should absorb. See docs/ShippingCostAnalyticsRoadmap.md (Pillar 3 — Recoup).
 *
 *   recoup delta = SUM(carrier_charges.amount for tracking)  −  carton.ship_cost
 *
 * "Actual" is the net of ALL charges on the tracking (base + every accessorial − credits), so
 * the delta captures post-ship corrections, DIM re-rates, residential surcharges and fuel
 * true-ups the ship-time rate never saw. No charge-category filtering is involved — this is
 * deliberately category-agnostic.
 */
class RecoupService
{
    /**
     * Service-name fragments that mark a charge as NOT an outbound customer shipment: "Collect"
     * (a vendor shipping to us on our account) and "Third Party" (billed to a third party).
     * Neither is recoupable to a customer, so they're excluded from coverage/blind-spot metrics.
     *
     * @var array<int, string>
     */
    private const NON_RECOUPABLE_SERVICE_MARKERS = ['%Collect%', '%Third Party%'];

    /**
     * Restrict a carrier_charges query to outbound (recoupable) shipments — dropping inbound
     * Collect and Third-Party lines. Service may be null (older/other sources); those are kept.
     */
    protected function onlyOutbound(Builder $query, string $column = 'cc.service'): Builder
    {
        return $query->where(function ($q) use ($column): void {
            $q->whereNull($column);
            $q->orWhere(function ($q2) use ($column): void {
                foreach (self::NON_RECOUPABLE_SERVICE_MARKERS as $marker) {
                    $q2->where($column, 'not like', $marker);
                }
            });
        });
    }

    /**
     * Per-tracking recoup candidates: cartons whose invoiced total exceeds the recorded ship
     * cost by at least $minDelta and haven't been recouped yet. Largest delta first.
     *
     * @return Collection<int, object{tracking_number:string, pace_job_number:?string, pace_customer_id:?string, ship_date:?string, ship_cost:float, actual:float, delta:float}>
     */
    public function candidates(float $minDelta = 0.01): Collection
    {
        return DB::table('carrier_charges as cc')
            ->join('carton_costs as kc', 'kc.tracking_number', '=', 'cc.tracking_number')
            ->whereNotNull('cc.tracking_number')
            ->whereNull('kc.recouped_at')
            // A carton with no recorded cost (0, e.g. pre-Process-Shipper shipments) has no valid
            // baseline — actual − 0 would look like a full-amount recoup. Only real costs qualify.
            ->whereRaw('kc.ship_cost > 0')
            ->groupBy('cc.tracking_number', 'kc.ship_cost', 'kc.pace_job_number', 'kc.pace_customer_id', 'kc.ship_date')
            ->selectRaw('cc.tracking_number,
                kc.pace_job_number,
                kc.pace_customer_id,
                kc.ship_date,
                ROUND(kc.ship_cost, 2) AS ship_cost,
                ROUND(SUM(cc.amount), 2) AS actual,
                ROUND(SUM(cc.amount) - kc.ship_cost, 2) AS delta')
            // Inline the threshold as a numeric literal ($minDelta is a typed float). A bound
            // parameter binds as TEXT, and SQLite orders every numeric below text, so a bound
            // `>= ?` silently matches nothing.
            ->havingRaw('ROUND(SUM(cc.amount) - kc.ship_cost, 2) >= '.sprintf('%.4f', $minDelta))
            ->orderByDesc('delta')
            ->get()
            ->map(function (object $r): object {
                $r->ship_cost = (float) $r->ship_cost;
                $r->actual = (float) $r->actual;
                $r->delta = (float) $r->delta;

                return $r;
            });
    }

    /**
     * Recoup rolled up per Pace customer — total recoupable, carton count, largest first.
     *
     * @return Collection<int, object{pace_customer_id:?string, cartons:int, recoupable:float}>
     */
    public function summaryByCustomer(float $minDelta = 0.01): Collection
    {
        return $this->candidates($minDelta)
            ->groupBy(fn (object $r): string => (string) ($r->pace_customer_id ?? ''))
            ->map(fn (Collection $rows, string $customer): object => (object) [
                'pace_customer_id' => $customer === '' ? null : $customer,
                'cartons' => $rows->count(),
                'recoupable' => round($rows->sum('delta'), 2),
            ])
            ->sortByDesc('recoupable')
            ->values();
    }

    /**
     * Total dollars recoupable across all unbilled candidates.
     */
    public function totalRecoupable(float $minDelta = 0.01): float
    {
        return round($this->candidates($minDelta)->sum('delta'), 2);
    }

    /**
     * Outbound tracking numbers that carry carrier charges but have no carton match — the recoup
     * blind spot (the carton source hasn't synced them, or they arrived on a master tracking).
     * Inbound Collect / Third-Party (vendor) shipments are excluded — they're not recoupable, so
     * they aren't a blind spot. Distinct tracking numbers with their invoiced total, largest first.
     *
     * @return Collection<int, object{tracking_number:string, actual:float}>
     */
    public function unmatchedTrackings(): Collection
    {
        $query = DB::table('carrier_charges as cc')
            ->leftJoin('carton_costs as kc', 'kc.tracking_number', '=', 'cc.tracking_number')
            ->whereNotNull('cc.tracking_number')
            ->whereNull('kc.id');

        return $this->onlyOutbound($query)
            ->groupBy('cc.tracking_number')
            ->selectRaw('cc.tracking_number, ROUND(SUM(cc.amount), 2) AS actual')
            ->orderByDesc('actual')
            ->get()
            ->map(function (object $r): object {
                $r->actual = (float) $r->actual;

                return $r;
            });
    }

    /**
     * Recoup carton coverage across outbound (recoupable) shipments: how many distinct tracking
     * numbers have a Pace carton vs not. Inbound Collect / Third-Party are excluded.
     *
     * @return object{total:int, matched:int, unmatched:int, pct:float}
     */
    public function coverage(): object
    {
        $query = DB::table('carrier_charges as cc')
            ->leftJoin('carton_costs as kc', 'kc.tracking_number', '=', 'cc.tracking_number')
            ->whereNotNull('cc.tracking_number');

        $row = $this->onlyOutbound($query)
            ->selectRaw('COUNT(DISTINCT cc.tracking_number) AS total, COUNT(DISTINCT kc.tracking_number) AS matched')
            ->first();

        $total = (int) ($row->total ?? 0);
        $matched = (int) ($row->matched ?? 0);

        return (object) [
            'total' => $total,
            'matched' => $matched,
            'unmatched' => $total - $matched,
            'pct' => $total > 0 ? round($matched / $total * 100, 1) : 0.0,
        ];
    }
}
