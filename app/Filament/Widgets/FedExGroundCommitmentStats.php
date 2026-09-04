<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BuildsCommitmentStats;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;

/**
 * FedEx Ground (Ground Domestic Single Piece) commitment tracking: the three agreement minimums for
 * the Ground bucket, plus the Unclassified tile (base transportation we couldn't map to a bucket —
 * the mapping-gap safety net, surfaced with drill-through rather than hidden).
 */
class FedExGroundCommitmentStats extends StatsOverviewWidget
{
    use BuildsCommitmentStats;
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'FedEx Ground Commitments';

    public function getDescription(): ?string
    {
        return $this->commitmentContext();
    }

    protected function getStats(): array
    {
        return $this->commitmentStats('ground', withUnclassified: true);
    }
}
