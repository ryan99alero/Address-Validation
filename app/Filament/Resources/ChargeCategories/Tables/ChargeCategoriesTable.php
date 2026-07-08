<?php

namespace App\Filament\Resources\ChargeCategories\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChargeCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('abbreviation')->label('Code')->badge()->color('gray'),
                TextColumn::make('name')->label('Fee Category')->searchable()->sortable(),
                TextColumn::make('pace_cost_center')
                    ->label('Pace Cost Center')
                    ->fontFamily('mono')
                    ->placeholder('— not set —')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'success' : 'gray'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
