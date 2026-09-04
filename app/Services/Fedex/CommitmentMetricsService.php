<?php

namespace App\Services\Fedex;

use App\Models\FedExCommitmentSetting;
use App\Support\UsFederalHolidays;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Measures our actual FedEx shipping activity against the six agreement volume commitments.
 *
 * Charge basis = "Annualized Transportation Charges": the GROSS, pre-discount base transportation
 * charge only. FedEx books discounts as their own "Discount / Credit" category and fuel/accessorials
 * as their own categories, so summing only the "Base Transportation" charge lines yields gross,
 * ex-fuel, ex-accessorial — exactly what the agreement measures. Service is resolved per shipment via
 * carrier_shipments.service, joined by tracking (CSV-preferred), since charges carry no shipment FK.
 *
 * Buckets are an EXACT-match allowlist (config/fedex_commitments + the settings toggles); anything
 * unmatched is surfaced as "unclassified", never folded into a bucket. Every metric is a minimum.
 */
class CommitmentMetricsService
{
    private ?int $fedexId = null;

    private ?int $baseCategoryId = null;

    /**
     * The full report for the selected dashboard period (year 0/null = all time; month null = full
     * year), plus from/to and the day-count mode in force.
     *
     * @return array<string, mixed>
     */
    public function periodReport(?int $year, ?int $month): array
    {
        [$from, $to] = $this->range($year, $month);

        return $this->rangeReport($from, $to);
    }

    /**
     * The rolling 52-week window ending today — the basis FedEx actually evaluates on, regardless of
     * the dashboard period. Cheap enough to cache daily at the widget layer.
     *
     * @return array<string, mixed>
     */
    public function rollingReport(): array
    {
        $to = Carbon::today();
        $from = $to->copy()->subWeeks(52)->addDay();

        return $this->rangeReport($from->toDateString(), $to->toDateString());
    }

    /**
     * Compute both buckets + unclassified over an inclusive date range. One grouped-by-service DB
     * aggregate feeds all of it (no per-row pull into PHP).
     *
     * @return array{from: string, to: string, day_count_mode: string, express: array<string, mixed>, ground: array<string, mixed>, unclassified: array<string, mixed>}
     */
    public function rangeReport(string $from, string $to): array
    {
        $settings = FedExCommitmentSetting::instance();
        $services = $settings->bucketServices();
        $targets = $settings->targets();
        $mode = $settings->dayCountMode();

        $agg = $this->aggregate($from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'day_count_mode' => $mode,
            'express' => $this->bucketMetrics($agg, $services['express'], $this->days($mode, $from, $to, $services['express']), $targets['express']),
            'ground' => $this->bucketMetrics($agg, $services['ground'], $this->days($mode, $from, $to, $services['ground']), $targets['ground']),
            'unclassified' => $this->unclassified($agg, array_merge($services['express'], $services['ground'])),
        ];
    }

    /**
     * Gross base-transportation packages + revenue for the range, grouped by the shipment's resolved
     * service. The correlated subquery picks one service per tracking, preferring the CSV shipment.
     *
     * @return array<string, array{packages: int, revenue: float}>
     */
    private function aggregate(string $from, string $to): array
    {
        if ($this->fedexId() === 0 || $this->baseCategoryId() === 0) {
            return [];
        }

        $rows = DB::table('carrier_charges')
            ->where('carrier_id', $this->fedexId())
            ->where('charge_category_id', $this->baseCategoryId())
            ->where('amount', '>', 0)
            ->whereBetween('ship_date', [$from, $to])
            // Alias must NOT be "service": carrier_charges has a real `service` column that would
            // otherwise capture GROUP BY and collapse every row into one bogus group.
            ->selectRaw($this->serviceExpr().' AS svc, COUNT(*) AS packages, SUM(amount) AS revenue')
            ->groupBy('svc')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->svc] = ['packages' => (int) $row->packages, 'revenue' => (float) $row->revenue];
        }

        return $map;
    }

    /**
     * CSV-preferred per-tracking service pick, as a correlated subquery (portable across MySQL +
     * SQLite). Ordering by (source_type = 'csv') DESC puts the clean CSV service first.
     */
    private function serviceExpr(): string
    {
        return 'COALESCE((SELECT s.service FROM carrier_shipments s '
            .'WHERE s.carrier_id = carrier_charges.carrier_id '
            .'AND s.tracking_number = carrier_charges.tracking_number '
            ."AND s.service IS NOT NULL AND s.service <> '' "
            ."ORDER BY (s.source_type = 'csv') DESC LIMIT 1), 'UNKNOWN')";
    }

    /**
     * @param  array<string, array{packages: int, revenue: float}>  $agg
     * @param  array<int, string>  $services
     * @param  array{avg_daily_packages: float, avg_daily_revenue: float, avg_charge_per_package: float}  $target
     * @return array<string, mixed>
     */
    private function bucketMetrics(array $agg, array $services, int $days, array $target): array
    {
        $packages = 0;
        $revenue = 0.0;
        foreach ($services as $service) {
            if (isset($agg[$service])) {
                $packages += $agg[$service]['packages'];
                $revenue += $agg[$service]['revenue'];
            }
        }

        $avgDailyPackages = $days > 0 ? $packages / $days : null;
        $avgDailyRevenue = $days > 0 ? $revenue / $days : null;
        $avgChargePerPackage = $packages > 0 ? $revenue / $packages : null;

        return [
            'packages' => $packages,
            'revenue' => round($revenue, 2),
            'days' => $days,
            'services' => $services,
            'metrics' => [
                'avg_daily_packages' => $this->assess($avgDailyPackages, (float) $target['avg_daily_packages']),
                'avg_daily_revenue' => $this->assess($avgDailyRevenue, (float) $target['avg_daily_revenue']),
                'avg_charge_per_package' => $this->assess($avgChargePerPackage, (float) $target['avg_charge_per_package']),
            ],
        ];
    }

    /**
     * Grade one metric against its (minimum) target: red below, amber within the at-risk margin
     * above, green comfortably above, nodata when the denominator was zero.
     *
     * @return array{actual: ?float, target: float, variance: ?float, variance_pct: ?float, state: string, pass: bool}
     */
    private function assess(?float $actual, float $target): array
    {
        if ($actual === null) {
            return ['actual' => null, 'target' => $target, 'variance' => null, 'variance_pct' => null, 'state' => 'nodata', 'pass' => false];
        }

        $margin = (float) config('fedex_commitments.at_risk_margin', 0.10);
        $variance = $actual - $target;
        $variancePct = $target != 0.0 ? ($variance / $target) * 100 : null;

        $state = match (true) {
            $actual < $target => 'red',
            $actual <= $target * (1 + $margin) => 'amber',
            default => 'green',
        };

        return [
            'actual' => $actual,
            'target' => $target,
            'variance' => $variance,
            'variance_pct' => $variancePct,
            'state' => $state,
            'pass' => $actual >= $target,
        ];
    }

    /**
     * Everything not in a bucket — the mapping-gap safety net, surfaced (never dropped).
     *
     * @param  array<string, array{packages: int, revenue: float}>  $agg
     * @param  array<int, string>  $classified
     * @return array{packages: int, revenue: float, services: array<string, array{packages: int, revenue: float}>}
     */
    private function unclassified(array $agg, array $classified): array
    {
        $set = array_flip($classified);
        $packages = 0;
        $revenue = 0.0;
        $services = [];

        foreach ($agg as $service => $value) {
            if (isset($set[$service])) {
                continue;
            }
            $packages += $value['packages'];
            $revenue += $value['revenue'];
            $services[$service] = ['packages' => $value['packages'], 'revenue' => round($value['revenue'], 2)];
        }

        uasort($services, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return ['packages' => $packages, 'revenue' => round($revenue, 2), 'services' => $services];
    }

    /**
     * @param  array<int, string>  $services
     */
    private function days(string $mode, string $from, string $to, array $services): int
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($end->lessThan($start)) {
            return 0;
        }

        return match ($mode) {
            'calendar' => (int) $start->diffInDays($end) + 1,
            'active' => $this->activeDays($from, $to, $services),
            default => UsFederalHolidays::businessDays($start, $end),
        };
    }

    /**
     * Distinct ship dates with at least one base-transportation charge in the bucket.
     *
     * @param  array<int, string>  $services
     */
    private function activeDays(string $from, string $to, array $services): int
    {
        if ($services === [] || $this->fedexId() === 0 || $this->baseCategoryId() === 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($services), '?'));

        return (int) DB::table('carrier_charges')
            ->where('carrier_id', $this->fedexId())
            ->where('charge_category_id', $this->baseCategoryId())
            ->where('amount', '>', 0)
            ->whereBetween('ship_date', [$from, $to])
            ->whereRaw('('.$this->serviceExpr().") IN ($placeholders)", $services)
            ->distinct()
            ->count('ship_date');
    }

    /**
     * Resolve the selected dashboard period to an inclusive [from, to], clamped so we never count
     * future days. Year null/0 = all time (earliest base charge → today).
     *
     * @return array{0: string, 1: string}
     */
    private function range(?int $year, ?int $month): array
    {
        $today = Carbon::today();

        if ($year === null || $year === 0) {
            $min = DB::table('carrier_charges')
                ->where('carrier_id', $this->fedexId())
                ->where('charge_category_id', $this->baseCategoryId())
                ->min('ship_date');
            $from = $min !== null ? Carbon::parse($min) : $today->copy();

            return [$from->toDateString(), $today->toDateString()];
        }

        $start = Carbon::create($year, $month ?? 1, 1)->startOfDay();
        $end = $month !== null ? $start->copy()->endOfMonth() : $start->copy()->endOfYear();
        if ($end->greaterThan($today)) {
            $end = $today->copy();
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function fedexId(): int
    {
        return $this->fedexId ??= (int) (DB::table('carriers')->where('slug', 'fedex')->value('id') ?? 0);
    }

    private function baseCategoryId(): int
    {
        return $this->baseCategoryId ??= (int) (DB::table('charge_categories')->where('name', 'Base Transportation')->value('id') ?? 0);
    }
}
