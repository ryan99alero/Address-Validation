<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Bleed zone: accessorial load % by year — the share of spend that is surcharges/fees rather
 * than base transportation. A rising line means the carriers' accessorials are eating more of
 * every dollar. Respects the dashboard period filter: with a month selected it plots that same
 * month across years; the selected year's point is highlighted.
 */
class AccessorialLoadChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Accessorial Load % by Year';

    protected ?string $description = 'Accessorials (fuel, DAS, residential, DIM, corrections…) as a share of total spend.';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        $rows = ($month !== null ? $svc->yearlyTotalsForMonth($month) : $svc->yearlyTotals())
            ->filter(fn ($r): bool => $r->total > 0)->values();

        $this->heading = 'Accessorial Load % by Year'
            .($month !== null ? ' · '.Dashboard::MONTHS[$month] : '');

        // Highlight the selected year against the rest of the trend.
        $pointColors = $rows->map(fn ($r): string => $r->year === $year ? '#b45309' : '#f59e0b')->all();
        $pointRadii = $rows->map(fn ($r): int => $r->year === $year ? 6 : 3)->all();

        return [
            'datasets' => [[
                'label' => 'Accessorial load %'.($month !== null ? ' ('.Dashboard::MONTHS[$month].')' : ''),
                'data' => $rows->pluck('load_pct')->all(),
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'pointBackgroundColor' => $pointColors,
                'pointRadius' => $pointRadii,
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $rows->pluck('year')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
