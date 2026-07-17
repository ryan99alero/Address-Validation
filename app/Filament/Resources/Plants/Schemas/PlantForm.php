<?php

namespace App\Filament\Resources\Plants\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Plant Code')
                    ->required()
                    ->maxLength(50)
                    ->placeholder('e.g., PLANT002')
                    ->helperText('The value used on ship-via codes and batches (stored uppercase).'),
                TextInput::make('name')
                    ->label('Name')
                    ->maxLength(255)
                    ->placeholder('e.g., Wichita'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
