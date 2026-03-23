<?php

namespace App\Filament\Resources\Addresses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AddressForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Address Information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('input_name')
                            ->label('Recipient Name')
                            ->maxLength(255),
                        TextInput::make('input_company')
                            ->label('Company Name')
                            ->maxLength(255),
                        TextInput::make('input_address_1')
                            ->label('Address Line 1')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('input_address_2')
                            ->label('Address Line 2')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('input_city')
                            ->label('City')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('input_state')
                            ->label('State/Province')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('input_postal')
                            ->label('ZIP/Postal Code')
                            ->required()
                            ->maxLength(20),
                        Select::make('input_country')
                            ->label('Country')
                            ->options([
                                'US' => 'United States',
                                'CA' => 'Canada',
                                'MX' => 'Mexico',
                                'GB' => 'United Kingdom',
                            ])
                            ->default('US')
                            ->required()
                            ->searchable(),
                    ]),
                Section::make('Metadata')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextInput::make('external_reference')
                            ->label('External Reference/ID')
                            ->maxLength(255)
                            ->helperText('Optional reference number for tracking'),
                        Select::make('source')
                            ->options([
                                'manual' => 'Manual Entry',
                                'import' => 'File Import',
                                'api' => 'API',
                            ])
                            ->default('manual')
                            ->required()
                            ->disabled(),
                    ]),
            ]);
    }
}
