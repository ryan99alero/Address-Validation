<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CostIntelligenceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Cost Intelligence';

    protected ?string $description = 'Where the shipping money goes — most recent year, with the yearly trend.';

    protected function getStats(): array
    {
        $years = app(CostAnalyticsService::class)->yearlyTotals();
        $latest = $years->last();

        if (! $latest) {
            return [Stat::make('No invoice data yet', '—')];
        }

        $totalTrend = $years->pluck('total')->map(fn ($v): float => (float) $v)->all();
        $loadTrend = $years->pluck('load_pct')->map(fn ($v): float => (float) $v)->all();
        $cpsTrend = $years->pluck('cost_per_ship')->map(fn ($v): float => (float) $v)->all();

        return [
            Stat::make('Total Spend · '.$latest->year, '$'.number_format($latest->total))
                ->description('Base $'.number_format($latest->base).' + accessorials $'.number_format($latest->accessorial).' − credits $'.number_format(abs($latest->credit)))
                ->chart($totalTrend)
                ->color('primary'),

            Stat::make('Accessorial Load', $latest->load_pct.'%')
                ->description('share of spend above base transport')
                ->chart($loadTrend)
                ->color($latest->load_pct >= 30 ? 'danger' : ($latest->load_pct >= 20 ? 'warning' : 'success')),

            Stat::make('Cost / Shipment', '$'.number_format($latest->cost_per_ship, 2))
                ->description(number_format($latest->ships).' shipments')
                ->chart($cpsTrend)
                ->color('gray'),

            Stat::make('Address Correction Fees · '.$latest->year, '$'.number_format($latest->correction))
                ->description('avoidable — clean addresses before shipping')
                ->color($latest->correction > 0 ? 'warning' : 'success'),
        ];
    }
}
