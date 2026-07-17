<?php

namespace App\Filament\Resources\Plants\Tables;

use App\Filament\Support\GridCsv;
use App\Models\Plant;
use App\Models\ShipViaCode;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('codes')
                    ->label('Ship Via Codes')
                    ->badge()
                    ->getStateUsing(fn (Plant $record): int => ShipViaCode::where('plant_id', $record->code)->count())
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([GridCsv::menu(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
