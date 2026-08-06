<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Prevent zone: address-correction fee spend by year. As the validation engine reaches more
 * shipments this line should decline — it's the proof the address engine pays for itself.
 * Respects the dashboard period filter: with a month selected it plots that same month across
 * years; the selected year's bar is highlighted.
 */
class CorrectionCostTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Address Correction Fees by Year';

    protected ?string $description = 'Should trend down as address validation coverage grows. These fees are avoidable.';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        // Drill with the period filter: All years → yearly; a year → months; a year+month → days.
        if ($year === null) {
            $rows = $svc->yearlyTotals()->filter(fn ($r): bool => $r->correction > 0)->values();
            $labels = $rows->pluck('year')->all();
            $this->heading = 'Address Correction Fees by Year';
        } elseif ($month !== null) {
            $rows = $svc->dailyTotals($year, $month)->filter(fn ($r): bool => $r->correction > 0)->values();
            $labels = $rows->pluck('day')->all();
            $this->heading = 'Address Correction Fees by Day · '.Dashboard::MONTHS[$month].' '.$year;
        } else {
            $rows = $svc->monthlyTotals($year)->filter(fn ($r): bool => $r->correction > 0)->values();
            $labels = $rows->map(fn ($r): string => substr(Dashboard::MONTHS[$r->month], 0, 3))->all();
            $this->heading = 'Address Correction Fees by Month · '.$year;
        }

        $colors = array_fill(0, $rows->count(), '#ef4444');

        return [
            'datasets' => [[
                'label' => 'Address correction fees $',
                'data' => $rows->pluck('correction')->all(),
                'backgroundColor' => $colors,
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
