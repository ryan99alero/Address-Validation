<?php

namespace App\Filament\Resources\ChargeCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChargeCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Fee Category')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Fixed — the app references categories by name.'),
                        TextInput::make('abbreviation')->label('Badge')->maxLength(16),
                        TextInput::make('pace_cost_center')
                            ->label('Pace Cost Center')
                            ->maxLength(255)
                            ->helperText('Where charges of this type post in Pace on the recoup push.'),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),
            ]);
    }
}
