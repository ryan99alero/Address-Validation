<?php

namespace App\Filament\Widgets;

use App\Services\Recoup\RecoupService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Recoup zone (Pillar 3): carrier charges above what Process Shipper quoted at ship time —
 * billable back to the customer — plus carton coverage over outbound shipments. Inbound
 * Collect / Third-Party (vendor-on-our-account) shipments are excluded from coverage.
 */
class RecoupStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Recoup';

    protected ?string $description = 'Carrier charges above what we quoted at ship time — billable back to the customer.';

    protected function getStats(): array
    {
        $recoup = app(RecoupService::class);
        $candidates = $recoup->candidates();
        $coverage = $recoup->coverage();
        $total = round($candidates->sum('delta'), 2);

        return [
            Stat::make('Recoupable', '$'.number_format($total, 2))
                ->description($candidates->count().' shipments billed over quote (invoiced − ship cost)')
                ->color($total > 0 ? 'success' : 'gray'),

            Stat::make('Carton Coverage', $coverage->pct.'%')
                ->description(number_format($coverage->matched).' of '.number_format($coverage->total).' outbound shipments matched to Pace')
                ->color($coverage->pct >= 80 ? 'success' : ($coverage->pct >= 50 ? 'warning' : 'danger')),

            Stat::make('Unmatched', number_format($coverage->unmatched))
                ->description('outbound shipments with no Pace carton yet (vendor Collect/3rd-party excluded)')
                ->color($coverage->unmatched > 0 ? 'warning' : 'success'),
        ];
    }
}
