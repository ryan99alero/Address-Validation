<?php

namespace App\Filament\Resources\ChargeDrivers;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ChargeDrivers\Pages\EditChargeDriver;
use App\Filament\Resources\ChargeDrivers\Pages\ListChargeDrivers;
use App\Filament\Resources\ChargeDrivers\Schemas\ChargeDriverForm;
use App\Filament\Resources\ChargeDrivers\Tables\ChargeDriversTable;
use App\Models\ChargeDriver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The "Carrier Chargeback Codes" catalog — the operator-editable layer over the ChargeDriver enum:
 * what each driver means, how we can act on it (disposition), and how it maps to Pace for the
 * coming chargeback push. Edit-only: rows are seeded from the enum, so no create/delete.
 */
class ChargeDriverResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ChargeDriver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Carrier Chargeback Codes';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ChargeDriverForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChargeDriversTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChargeDrivers::route('/'),
            'edit' => EditChargeDriver::route('/{record}/edit'),
        ];
    }
}
