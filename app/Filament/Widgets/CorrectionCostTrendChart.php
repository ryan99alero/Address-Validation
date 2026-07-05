<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Prevent zone: address-correction fee spend by year. As the validation engine reaches more
 * shipments this line should decline — it's the proof the address engine pays for itself.
 * (Re-correction rate — the engine's accuracy KPI — arrives with the correction-event work.)
 */
class CorrectionCostTrendChart extends ChartWidget
{
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
        $years = app(CostAnalyticsService::class)->yearlyTotals()->filter(fn ($r): bool => $r->correction > 0);

        return [
            'datasets' => [[
                'label' => 'Address correction fees $',
                'data' => $years->pluck('correction')->all(),
                'backgroundColor' => '#ef4444',
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
