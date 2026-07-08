<?php

namespace App\Filament\Clusters\Integrations;

use App\Filament\Concerns\AdminOnly;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Configuration hub for every external connection: carrier APIs, ERP/Pace, folder, mail, SQL.
 * Each member resource keeps its own admin gating; the cluster is admin-only as a whole because
 * all five members are.
 */
class IntegrationsCluster extends Cluster
{
    use AdminOnly;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?int $navigationSort = 4;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
