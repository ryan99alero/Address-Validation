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

    protected static ?int $sort = 4;

    protected ?string $heading = 'Address Correction Fees by Year';

    protected ?string $description = 'Should trend down as address validation coverage grows. These fees are avoidable.';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        $rows = ($month !== null ? $svc->yearlyTotalsForMonth($month) : $svc->yearlyTotals())
            ->filter(fn ($r): bool => $r->correction > 0)->values();

        $this->heading = 'Address Correction Fees by Year'
            .($month !== null ? ' · '.Dashboard::MONTHS[$month] : '');

        // Highlight the selected year against the rest of the trend.
        $colors = $rows->map(fn ($r): string => $r->year === $year ? '#b91c1c' : '#ef4444')->all();

        return [
            'datasets' => [[
                'label' => 'Address correction fees $'.($month !== null ? ' ('.Dashboard::MONTHS[$month].')' : ''),
                'data' => $rows->pluck('correction')->all(),
                'backgroundColor' => $colors,
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
