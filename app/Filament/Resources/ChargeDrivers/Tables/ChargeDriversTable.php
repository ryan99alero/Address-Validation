<?php

namespace App\Filament\Resources\ChargeDrivers\Tables;

use App\Enums\ChargeDisposition;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChargeDriversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('abbreviation')
                    ->label('Code')
                    ->badge()
                    ->color(fn ($record): string => $record->color ?: 'gray'),
                TextColumn::make('label')->label('Driver')->searchable()->sortable(),
                TextColumn::make('disposition')
                    ->label('We can…')
                    ->badge()
                    ->formatStateUsing(fn (ChargeDisposition $state): string => $state->label())
                    ->color(fn (ChargeDisposition $state): string => match ($state) {
                        ChargeDisposition::CustomerChargebackable => 'warning',
                        ChargeDisposition::CarrierDisputable => 'info',
                        ChargeDisposition::Informational => 'gray',
                    }),
                TextColumn::make('pace_activity_code')
                    ->label('Pace Code')
                    ->fontFamily('mono')
                    ->placeholder('—'),
                IconColumn::make('push_to_pace')->label('Push')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('disposition')
                    ->options(collect(ChargeDisposition::cases())->mapWithKeys(fn (ChargeDisposition $d) => [$d->value => $d->label()])),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort_order');
    }
}
