<?php

namespace App\Filament\Resources\CarrierChargeTypes;

use App\Filament\Clusters\ChargeClassification\ChargeClassificationCluster;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\CarrierChargeTypes\Pages\CreateCarrierChargeType;
use App\Filament\Resources\CarrierChargeTypes\Pages\EditCarrierChargeType;
use App\Filament\Resources\CarrierChargeTypes\Pages\ListCarrierChargeTypes;
use App\Filament\Resources\CarrierChargeTypes\Schemas\CarrierChargeTypeForm;
use App\Filament\Resources\CarrierChargeTypes\Tables\CarrierChargeTypesTable;
use App\Models\CarrierChargeType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Carrier Charge Crosswalk — the operator-editable map from "what a carrier calls a charge" (its
 * CSV header label and/or PDF line label, per carrier) to one of our universal Fee Categories. The
 * resolver consults this ahead of the legacy code mappings, so editing a row here is how the
 * operator (re)classifies charges without code changes. Rows with no category are the review
 * worklist (nav badge).
 */
class CarrierChargeTypeResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = CarrierChargeType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $cluster = ChargeClassificationCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Carrier Charge Crosswalk';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function getNavigationBadge(): ?string
    {
        $n = CarrierChargeType::query()->whereNull('charge_category_id')->where('is_active', true)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return CarrierChargeTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarrierChargeTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarrierChargeTypes::route('/'),
            'create' => CreateCarrierChargeType::route('/create'),
            'edit' => EditCarrierChargeType::route('/{record}/edit'),
        ];
    }
}
