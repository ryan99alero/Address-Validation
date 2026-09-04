<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BuildsCommitmentStats;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;

/**
 * FedEx Express (Domestic Express Non-Freight) commitment tracking: the three agreement minimums for
 * the Express bucket, graded against the selected dashboard period with the rolling-52-week figure
 * that FedEx actually evaluates on. Missing a minimum lets FedEx reprice our rates on 30 days' notice.
 */
class FedExExpressCommitmentStats extends StatsOverviewWidget
{
    use BuildsCommitmentStats;
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'FedEx Express Commitments';

    public function getDescription(): ?string
    {
        return $this->commitmentContext();
    }

    protected function getStats(): array
    {
        return $this->commitmentStats('express');
    }
}
