<?php

namespace App\Filament\Resources\CarrierAccounts;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\ListCarrierAccounts;
use App\Filament\Resources\CarrierAccounts\Schemas\CarrierAccountForm;
use App\Filament\Resources\CarrierAccounts\Tables\CarrierAccountsTable;
use App\Models\CarrierAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CarrierAccountResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = CarrierAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Carrier Accounts';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return CarrierAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarrierAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /** Surface the "needs owner" worklist as a nav badge. */
    public static function getNavigationBadge(): ?string
    {
        $count = CarrierAccount::whereNull('account_owner_id')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarrierAccounts::route('/'),
            'create' => CreateCarrierAccount::route('/create'),
            'edit' => EditCarrierAccount::route('/{record}/edit'),
        ];
    }
}
