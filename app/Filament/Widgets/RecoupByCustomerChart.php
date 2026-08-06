<?php

namespace App\Filament\Widgets;

use App\Services\Recoup\RecoupService;
use Filament\Widgets\ChartWidget;

/**
 * Recoup zone: the customers with the most billable carrier overage (invoiced − ship cost),
 * so recovery effort goes where the money is. Salesperson attribution follows once Pace
 * write-back records who owns each job.
 */
class RecoupByCustomerChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Recoupable by Customer';

    protected ?string $description = 'Top Pace customers by billable carrier overage.';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = app(RecoupService::class)->summaryByCustomer()->take(12);

        return [
            'datasets' => [[
                'label' => 'Recoupable $',
                'data' => $rows->pluck('recoupable')->map(fn ($v): float => (float) $v)->all(),
                'backgroundColor' => '#22c55e',
            ]],
            'labels' => $rows->pluck('pace_customer_id')->map(fn (?string $c): string => $c ?? 'Unknown')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => ['x' => ['beginAtZero' => true]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
