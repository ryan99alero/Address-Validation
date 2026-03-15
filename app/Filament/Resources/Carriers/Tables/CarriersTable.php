<?php

namespace App\Filament\Resources\Carriers\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CarriersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('environment')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'production' => 'success',
                        'sandbox' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_connected_at')
                    ->label('Last Connected')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('last_error_at')
                    ->label('Last Error')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('None')
                    ->color('danger'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
