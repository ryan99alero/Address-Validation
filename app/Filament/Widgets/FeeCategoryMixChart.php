<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Bleed zone: which accessorial categories cost the most in the selected period, base transport
 * excluded — so fuel / DAS / residential / additional handling / corrections stand out.
 */
class FeeCategoryMixChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Accessorial Spend by Category';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);
        $mix = $svc->periodCategoryMix($year, $month)->take(12);

        $this->heading = 'Accessorial Spend by Category · '.$this->periodLabel($year, $month);

        return [
            'datasets' => [[
                'label' => 'Billed $',
                'data' => $mix->pluck('total')->all(),
                'backgroundColor' => '#6366f1',
            ]],
            'labels' => $mix->pluck('category')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y', // horizontal bars — category labels are long
            'scales' => ['x' => ['beginAtZero' => true]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
