<?php

namespace App\Filament\Clusters\ChargeClassification;

use App\Filament\Concerns\AdminOnly;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Configuration hub merging the two charge-normalization catalogs: Fee Categories (what a charge
 * is + its Pace cost center) and Carrier Chargeback Codes (why we were billed + disposition +
 * Pace push flag). Admin-only, matching both member resources.
 */
class ChargeClassificationCluster extends Cluster
{
    use AdminOnly;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Charge Classification';

    protected static ?int $navigationSort = 1;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;
}
