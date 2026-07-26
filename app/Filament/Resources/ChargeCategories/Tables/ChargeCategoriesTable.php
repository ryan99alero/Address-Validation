<?php

namespace App\Filament\Resources\ChargeCategories\Tables;

use App\Models\ChargeCategory;
use Filament\Actions\DeleteAction;
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
                IconColumn::make('is_system')->label('System')->boolean()
                    ->trueIcon('heroicon-o-lock-closed')->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')->falseColor('gray')
                    ->tooltip(fn (ChargeCategory $record): ?string => $record->is_system ? 'Referenced by name in code — name locked, cannot delete.' : null),
                TextColumn::make('charge_types_count')->label('Mapped Types')->counts('chargeTypes')->badge()->color('info')->alignEnd(),
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
                DeleteAction::make()
                    // Block deleting system categories or any still in use (cascade would drop their
                    // legacy mappings and null out charges/crosswalk rows).
                    ->hidden(fn (ChargeCategory $record): bool => $record->is_system
                        || $record->charges()->exists()
                        || $record->chargeTypes()->exists()
                        || $record->mappings()->exists()),
            ])
            ->defaultSort('name');
    }
}
