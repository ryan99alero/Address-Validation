<?php

namespace App\Filament\Resources\ChargeDrivers\Schemas;

use App\Enums\ChargeDisposition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChargeDriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Driver')
                    ->description('What this charge driver means. The key is fixed (the app switches on it); everything else is yours to tune.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label('Key')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Fixed identifier used by the app.'),
                        TextInput::make('abbreviation')->label('Badge')->maxLength(16),
                        TextInput::make('label')->label('Label')->required()->maxLength(255),
                        TextInput::make('description')->label('Description')->maxLength(255)->columnSpanFull(),
                    ]),

                Section::make('Chargeback')
                    ->description('How we can act on this charge, and how it maps to Pace when the chargeback push is built.')
                    ->columns(2)
                    ->schema([
                        Select::make('disposition')
                            ->label('Disposition')
                            ->options(collect(ChargeDisposition::cases())->mapWithKeys(fn (ChargeDisposition $d) => [$d->value => $d->label()]))
                            ->required()
                            ->native(false)
                            ->helperText('Charge back to customer, dispute with the carrier, or informational only.'),
                        TextInput::make('pace_activity_code')
                            ->label('Pace Activity Code')
                            ->maxLength(255)
                            ->helperText('The Pace GL / activity code to post this to.'),
                        Toggle::make('push_to_pace')
                            ->label('Push to Pace')
                            ->helperText('Include this driver when the Pace chargeback integration runs.'),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),
            ]);
    }
}
