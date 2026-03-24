<?php

namespace App\Filament\Resources\AddressCorrections;

use App\Filament\Resources\AddressCorrections\Pages\ListAddressCorrections;
use App\Filament\Resources\AddressCorrections\Pages\ViewAddressCorrection;
use App\Filament\Resources\AddressCorrections\Tables\AddressCorrectionsTable;
use App\Models\CarrierInvoiceLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AddressCorrectionResource extends Resource
{
    protected static ?string $model = CarrierInvoiceLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Address Corrections';

    protected static ?string $navigationLabel = 'All Corrections';

    protected static ?string $modelLabel = 'Address Correction';

    protected static ?string $pluralModelLabel = 'Address Corrections';

    protected static ?string $slug = 'address-corrections';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return AddressCorrectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAddressCorrections::route('/'),
            'view' => ViewAddressCorrection::route('/{record}'),
        ];
    }
}
