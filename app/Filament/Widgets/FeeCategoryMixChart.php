<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Bleed zone: which accessorial categories cost the most (most recent year), base transport
 * excluded — so fuel / DAS / residential / additional handling / corrections stand out.
 */
class FeeCategoryMixChart extends ChartWidget
{
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
        $year = $svc->latestYear()?->year;
        $mix = $svc->categoryMix($year)->take(12);

        $this->heading = 'Accessorial Spend by Category'.($year ? ' · '.$year : '');

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
