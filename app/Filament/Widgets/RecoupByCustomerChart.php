<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use App\Services\Recoup\RecoupService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Recoup zone: the customers with the most billable carrier overage (invoiced − ship cost),
 * so recovery effort goes where the money is. Scoped to the dashboard timeline. Salesperson
 * attribution follows once Pace write-back records who owns each job.
 */
class RecoupByCustomerChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Recoupable by Customer';

    protected ?string $description = 'Top Pace customers by billable carrier overage.';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '220px';

    protected string $view = 'filament.widgets.expandable-chart';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$year, $month] = $this->selectedPeriod(app(CostAnalyticsService::class));
        $rows = app(RecoupService::class)->summaryByCustomer(year: $year, month: $month)->take(12);

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
