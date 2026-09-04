<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Resources\CarrierShipmentSummaries\CarrierShipmentSummaryResource;
use App\Models\FedExCommitmentSetting;
use App\Services\Analytics\CostAnalyticsService;
use App\Services\Fedex\CommitmentMetricsService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shared rendering for the two FedEx commitment stat widgets. Both read the same cached period +
 * rolling-52-week reports (computed once, shared across the two widgets), then render one bucket's
 * three metrics as pass/fail Stats with a target, variance, and the rolling-52-week figure that
 * actually governs. Requires the host widget to also use InteractsWithPageFilters + ReadsDashboardPeriod.
 */
trait BuildsCommitmentStats
{
    /**
     * @return array<int, Stat>
     */
    protected function commitmentStats(string $bucket, bool $withUnclassified = false): array
    {
        [$period, $rolling] = $this->commitmentReports();
        $data = $period[$bucket];
        $roll = $rolling[$bucket];

        $labels = [
            'avg_daily_packages' => 'Avg Daily Packages',
            'avg_daily_revenue' => 'Avg Daily Gross Revenue',
            'avg_charge_per_package' => 'Avg Gross Charge / Package',
        ];

        $stats = [];
        foreach ($labels as $key => $label) {
            $metric = $data['metrics'][$key];
            $rollMetric = $roll['metrics'][$key];

            $stats[] = Stat::make($label, $this->fmt($key, $metric['actual']))
                ->description($this->metricDescription($key, $metric, $rollMetric))
                ->descriptionIcon($this->stateIcon($metric['state']))
                ->color($this->stateColor($metric['state']))
                ->url($this->drillUrl($period['from'], $period['to']));
        }

        if ($withUnclassified) {
            $stats[] = $this->unclassifiedStat($period);
        }

        return $stats;
    }

    /**
     * The cached period + rolling reports, computed once and shared by both widgets. The cache key
     * carries the settings signature so editing targets/toggles busts it immediately.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function commitmentReports(): array
    {
        [$year, $month] = $this->selectedPeriod(app(CostAnalyticsService::class));
        $sig = (string) (FedExCommitmentSetting::instance()->updated_at?->timestamp ?? 0);
        $svc = app(CommitmentMetricsService::class);

        $period = Cache::remember(
            'fedex_commit:period:'.($year ?? 'all').':'.($month ?? 'full').':'.$sig,
            600,
            fn (): array => $svc->periodReport($year, $month),
        );
        $rolling = Cache::remember(
            'fedex_commit:rolling:'.now()->toDateString().':'.$sig,
            86400,
            fn (): array => $svc->rollingReport(),
        );

        return [$period, $rolling];
    }

    /**
     * A one-line context string for the widget description: what's measured, the period, the
     * day-count mode, and the optional-toggle state (so the number is never ambiguous).
     */
    protected function commitmentContext(): string
    {
        [$period] = $this->commitmentReports();
        [$year, $month] = $this->selectedPeriod(app(CostAnalyticsService::class));
        $toggles = implode(' · ', FedExCommitmentSetting::instance()->optionalStatusLabels());

        return 'Gross base transportation vs the agreement minimums · '
            .$this->periodLabel($year, $month).' · '
            .$period['day_count_mode'].' days · '.$toggles;
    }

    /**
     * @param  array<string, mixed>  $period
     */
    protected function unclassifiedStat(array $period): Stat
    {
        $u = $period['unclassified'];
        $top = array_slice(array_keys($u['services']), 0, 3);
        $detail = count($u['services']).' service(s)'.($top !== [] ? ': '.implode(', ', $top) : '');

        return Stat::make('Unclassified — not counted', '$'.number_format((float) $u['revenue'], 2))
            ->description($u['packages'].' packages · '.$detail)
            ->descriptionIcon('heroicon-m-question-mark-circle')
            ->color($u['revenue'] > 0 ? 'warning' : 'gray')
            ->url($this->drillUrl($period['from'], $period['to']));
    }

    /**
     * @param  array<string, mixed>  $metric
     * @param  array<string, mixed>  $rolling
     */
    protected function metricDescription(string $key, array $metric, array $rolling): string
    {
        $desc = 'Target '.$this->fmt($key, $metric['target']);

        if ($metric['actual'] !== null) {
            $pct = $metric['variance_pct'] !== null
                ? ' ('.($metric['variance_pct'] >= 0 ? '+' : '').number_format($metric['variance_pct'], 0).'%)'
                : '';
            $desc .= ' · '.$this->fmtVariance($key, $metric['variance']).$pct;
        } else {
            $desc .= ' · no data';
        }

        return $desc.' · rolling 52-wk '.$this->fmt($key, $rolling['actual']);
    }

    protected function drillUrl(string $from, string $to): string
    {
        $fedexId = (int) (DB::table('carriers')->where('slug', 'fedex')->value('id') ?? 0);

        return CarrierShipmentSummaryResource::getUrl('index', [
            'tableFilters' => [
                'carrier_id' => ['value' => $fedexId],
                'ship_date' => ['from' => $from, 'until' => $to],
            ],
        ]);
    }

    protected function fmt(string $key, ?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $key === 'avg_daily_packages'
            ? number_format($value, 2)
            : '$'.number_format($value, 2);
    }

    protected function fmtVariance(string $key, ?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value >= 0 ? '+' : '-').$this->fmt($key, abs($value));
    }

    protected function stateColor(string $state): string
    {
        return match ($state) {
            'green' => 'success',
            'amber' => 'warning',
            'red' => 'danger',
            default => 'gray',
        };
    }

    protected function stateIcon(string $state): string
    {
        return match ($state) {
            'green' => 'heroicon-m-check-circle',
            'amber' => 'heroicon-m-exclamation-triangle',
            'red' => 'heroicon-m-x-circle',
            default => 'heroicon-m-minus-circle',
        };
    }
}
