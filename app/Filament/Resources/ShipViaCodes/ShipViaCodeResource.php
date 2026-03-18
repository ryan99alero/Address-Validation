<?php

namespace App\Filament\Resources\ShipViaCodes;

use App\Filament\Resources\ShipViaCodes\Pages\CreateShipViaCode;
use App\Filament\Resources\ShipViaCodes\Pages\EditShipViaCode;
use App\Filament\Resources\ShipViaCodes\Pages\ListShipViaCodes;
use App\Filament\Resources\ShipViaCodes\Schemas\ShipViaCodeForm;
use App\Filament\Resources\ShipViaCodes\Tables\ShipViaCodesTable;
use App\Models\ShipViaCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShipViaCodeResource extends Resource
{
    protected static ?string $model = ShipViaCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Ship Via Codes';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return ShipViaCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipViaCodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipViaCodes::route('/'),
            'create' => CreateShipViaCode::route('/create'),
            'edit' => EditShipViaCode::route('/{record}/edit'),
        ];
    }
}
