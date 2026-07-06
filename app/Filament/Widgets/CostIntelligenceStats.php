<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CostIntelligenceStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Cost Intelligence';

    protected ?string $description = 'Where the shipping money goes — for the selected period, versus the same period a year earlier.';

    protected function getStats(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        if ($year === null) {
            return [Stat::make('No invoice data yet', '—')];
        }

        $current = $svc->periodTotals($year, $month);
        $prior = $svc->periodTotals($year - 1, $month);
        $priorLabel = $this->periodLabel($year - 1, $month);

        // Full-history yearly trend for the card sparklines (trajectory, independent of the filter).
        $years = $svc->yearlyTotals();
        $totalTrend = $years->pluck('total')->map(fn ($v): float => (float) $v)->all();
        $loadTrend = $years->pluck('load_pct')->map(fn ($v): float => (float) $v)->all();
        $cpsTrend = $years->pluck('cost_per_ship')->map(fn ($v): float => (float) $v)->all();

        $label = $this->periodLabel($year, $month);

        $totalDelta = $this->delta($current->total, $prior->total, $priorLabel);
        $loadDelta = $this->delta($current->load_pct, $prior->load_pct, $priorLabel, '%pt');
        $cpsDelta = $this->delta($current->cost_per_ship, $prior->cost_per_ship, $priorLabel);
        $corrDelta = $this->delta($current->correction, $prior->correction, $priorLabel);

        return [
            Stat::make('Total Spend · '.$label, '$'.number_format($current->total))
                ->description($totalDelta['text'] ?: 'Base $'.number_format($current->base).' + accessorials $'.number_format($current->accessorial))
                ->descriptionIcon($totalDelta['icon'], IconPosition::Before)
                ->chart($totalTrend)
                ->color($totalDelta['color'] ?? 'primary'),

            Stat::make('Accessorial Load', $current->load_pct.'%')
                ->description($loadDelta['text'] ?: 'share of spend above base transport')
                ->descriptionIcon($loadDelta['icon'], IconPosition::Before)
                ->chart($loadTrend)
                ->color($loadDelta['color'] ?? ($current->load_pct >= 30 ? 'danger' : 'warning')),

            Stat::make('Cost / Shipment', '$'.number_format($current->cost_per_ship, 2))
                ->description($cpsDelta['text'] ?: number_format($current->ships).' shipments')
                ->descriptionIcon($cpsDelta['icon'], IconPosition::Before)
                ->chart($cpsTrend)
                ->color($cpsDelta['color'] ?? 'gray'),

            Stat::make('Address Correction Fees · '.$label, '$'.number_format($current->correction))
                ->description($corrDelta['text'] ?: 'avoidable — clean addresses before shipping')
                ->descriptionIcon($corrDelta['icon'], IconPosition::Before)
                ->color($corrDelta['color'] ?? ($current->correction > 0 ? 'warning' : 'success')),
        ];
    }

    /**
     * Year-over-year delta for a cost metric (lower is better): up = danger/▲, down = success/▼.
     * A percentage-point metric (load %) uses %pt so the arithmetic delta is shown, not a ratio.
     *
     * @return array{text: string, icon: ?string, color: ?string}
     */
    private function delta(float $current, float $prior, string $priorLabel, string $unit = '%'): array
    {
        if ($prior <= 0.0) {
            return ['text' => '', 'icon' => null, 'color' => null];
        }

        $change = $unit === '%pt'
            ? round($current - $prior, 1)
            : round(($current - $prior) / $prior * 100, 1);

        if ($change === 0.0) {
            return ['text' => 'flat vs '.$priorLabel, 'icon' => 'heroicon-m-minus-small', 'color' => 'gray'];
        }

        $up = $change > 0;
        $magnitude = $unit === '%pt' ? abs($change).' pts' : abs($change).'%';

        return [
            'text' => ($up ? '▲ ' : '▼ ').$magnitude.' vs '.$priorLabel,
            'icon' => $up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down',
            'color' => $up ? 'danger' : 'success',
        ];
    }
}
