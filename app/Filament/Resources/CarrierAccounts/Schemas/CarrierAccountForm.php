<?php

namespace App\Filament\Resources\CarrierAccounts\Schemas;

use App\Models\AccountOwner;
use App\Models\Carrier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarrierAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(Carrier::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->native(false),
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->required()
                            ->maxLength(50)
                            ->placeholder('e.g., 067201043'),
                        TextInput::make('nickname')
                            ->label('Nickname')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Plant002 FedEx Ground')
                            ->helperText('A human handle — account numbers are opaque.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Ownership')
                    ->schema([
                        Select::make('account_owner_id')
                            ->label('Owner')
                            ->relationship('owner', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->placeholder('— needs owner —')
                            ->helperText('Who the bill goes to. BestWay pools all of one owner\'s accounts on a plant into a single service ladder and never crosses to another owner.')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique('account_owners', 'name'),
                                Select::make('type')
                                    ->options(AccountOwner::typeOptions())
                                    ->default(AccountOwner::TYPE_CUSTOMER)
                                    ->required()
                                    ->native(false),
                            ]),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Turn off to stop BestWay routing to this account (e.g. an account being closed).'),
                        Textarea::make('notes')
                            ->rows(2)
                            ->maxLength(1000),
                    ]),
            ]);
    }
}
