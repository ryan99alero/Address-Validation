<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Bleed zone: accessorial load % by year — the share of spend that is surcharges/fees rather
 * than base transportation. A rising line means the carriers' accessorials are eating more of
 * every dollar.
 */
class AccessorialLoadChart extends ChartWidget
{
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
        $years = app(CostAnalyticsService::class)->yearlyTotals()->filter(fn ($r): bool => $r->total > 0);

        return [
            'datasets' => [[
                'label' => 'Accessorial load %',
                'data' => $years->pluck('load_pct')->all(),
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $years->pluck('year')->all(),
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
