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

        // Drill with the period filter: All years → yearly; a year → months; a year+month → days.
        if ($year === null) {
            $rows = $svc->yearlyTotals()->filter(fn ($r): bool => $r->total > 0)->values();
            $labels = $rows->pluck('year')->all();
            $this->heading = 'Accessorial Load % by Year';
        } elseif ($month !== null) {
            $rows = $svc->dailyTotals($year, $month)->filter(fn ($r): bool => $r->total > 0)->values();
            $labels = $rows->pluck('day')->all();
            $this->heading = 'Accessorial Load % by Day · '.Dashboard::MONTHS[$month].' '.$year;
        } else {
            $rows = $svc->monthlyTotals($year)->filter(fn ($r): bool => $r->total > 0)->values();
            $labels = $rows->map(fn ($r): string => substr(Dashboard::MONTHS[$r->month], 0, 3))->all();
            $this->heading = 'Accessorial Load % by Month · '.$year;
        }

        $pointColors = array_fill(0, $rows->count(), '#f59e0b');
        $pointRadii = array_fill(0, $rows->count(), 3);

        return [
            'datasets' => [[
                'label' => 'Accessorial load %',
                'data' => $rows->pluck('load_pct')->all(),
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'pointBackgroundColor' => $pointColors,
                'pointRadius' => $pointRadii,
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $labels,
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
