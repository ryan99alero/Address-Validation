<?php

namespace App\Filament\Resources\CarrierCharges;

use App\Filament\Resources\CarrierCharges\Pages\ListCarrierCharges;
use App\Filament\Resources\CarrierCharges\Tables\CarrierChargesTable;
use App\Models\CarrierCharge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CarrierChargeResource extends Resource
{
    protected static ?string $model = CarrierCharge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Costs';

    protected static ?string $navigationLabel = 'All Charges';

    protected static ?string $modelLabel = 'Charge';

    protected static ?string $pluralModelLabel = 'Charges';

    protected static ?string $slug = 'adjustments';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['carrier', 'category', 'invoice', 'cartonCost']);
    }

    public static function table(Table $table): Table
    {
        return CarrierChargesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarrierCharges::route('/'),
        ];
    }
}
