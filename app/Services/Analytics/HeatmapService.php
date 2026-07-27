<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a destination-bearing query into map heat points ([lat, lng, weight]) by joining the
 * static zip_centroids table on the 5-digit ZIP — no per-address geocoding, no external calls.
 * Results are cached and keyed to a table version stamp, so repeat loads are instant and it
 * recomputes only when new rows land. Used by the shipping and correction heatmap widgets.
 */
class HeatmapService
{
    /**
     * Shipping destinations from carrier_shipments (UPS-sourced today).
     *
     * @return array{points: list<array{0: float, 1: float, 2: int}>, meta: array{matched: int, total: int, unmatched: int, max: float}}
     */
    public function shipments(?int $year, ?int $month): array
    {
        return $this->aggregate(
            'shipments',
            'carrier_shipments',
            DB::table('carrier_shipments as s'),
            's.zip',
            's.ship_date',
            $year,
            $month,
        );
    }

    /**
     * Address corrections from carrier_invoice_lines, optionally limited to a single carrier slug
     * (so UPS and FedEx can be compared).
     *
     * @return array{points: list<array{0: float, 1: float, 2: int}>, meta: array{matched: int, total: int, unmatched: int, max: float}}
     */
    public function corrections(?int $year, ?int $month, ?string $carrierSlug = null): array
    {
        $base = DB::table('carrier_invoice_lines as s')
            ->join('carrier_invoices as ci', 'ci.id', '=', 's.carrier_invoice_id')
            ->join('carriers as c', 'c.id', '=', 'ci.carrier_id')
            ->when($carrierSlug, fn (Builder $q): Builder => $q->where('c.slug', $carrierSlug));

        return $this->aggregate(
            'corrections:'.($carrierSlug ?? 'all'),
            'carrier_invoice_lines',
            $base,
            's.original_postal',
            's.ship_date',
            $year,
            $month,
        );
    }

    /**
     * @return array{points: list<array{0: float, 1: float, 2: int}>, meta: array{matched: int, total: int, unmatched: int, max: float}}
     */
    private function aggregate(string $tag, string $versionTable, Builder $base, string $zipCol, string $dateCol, ?int $year, ?int $month): array
    {
        $version = DB::table($versionTable)->max('id');
        $cacheKey = 'heatmap:'.$tag.':'.md5($version.'|'.$year.'|'.$month);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($base, $zipCol, $dateCol, $year, $month): array {
            // A date range (not YEAR()/MONTH()) so the ship_date index is used and the SQL is
            // portable; whereMonth only for the rare "all years, single month" case.
            $applyPeriod = function (Builder $q) use ($dateCol, $year, $month): Builder {
                if ($year !== null) {
                    $start = sprintf('%04d-%02d-01', $year, $month ?? 1);
                    $end = $month !== null
                        ? date('Y-m-01', (int) strtotime($start.' +1 month'))
                        : sprintf('%04d-01-01', $year + 1);
                    $q->where($dateCol, '>=', $start)->where($dateCol, '<', $end);
                } elseif ($month !== null) {
                    $q->whereMonth($dateCol, $month);
                }

                return $q;
            };

            // Every destination in the period that carries a ZIP (denominator for the match rate).
            $total = (int) $applyPeriod((clone $base))
                ->whereRaw("NULLIF(TRIM({$zipCol}), '') IS NOT NULL")
                ->count();

            // Join the static centroid table on the 5-digit ZIP; non-US / bad ZIPs simply don't match.
            $rows = $applyPeriod((clone $base))
                ->join('zip_centroids as z', 'z.zip', '=', DB::raw("SUBSTR({$zipCol}, 1, 5)"))
                ->selectRaw('z.lat, z.lng, COUNT(*) AS w')
                ->groupBy('z.zip', 'z.lat', 'z.lng')
                ->get();

            $matched = (int) $rows->sum('w');

            return [
                'points' => $rows->map(fn ($r): array => [round((float) $r->lat, 4), round((float) $r->lng, 4), (int) $r->w])->all(),
                'meta' => [
                    'matched' => $matched,
                    'total' => $total,
                    'unmatched' => max(0, $total - $matched),
                    'max' => (float) ($rows->max('w') ?? 0),
                ],
            ];
        });
    }
}
