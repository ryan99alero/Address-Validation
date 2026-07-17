<?php

namespace App\Filament\Resources\IntegrationConnections\Tables;

use App\Filament\Support\GridCsv;
use App\Models\IntegrationConnection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('driver')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        IntegrationConnection::DRIVER_PACE => 'Pace / ePace ERP',
                        IntegrationConnection::DRIVER_GENERIC_REST => 'Generic REST',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => $state === IntegrationConnection::DRIVER_PACE ? 'info' : 'gray'),
                TextColumn::make('base_url')
                    ->label('Base URL')
                    ->limit(40)
                    ->color('gray'),
                TextColumn::make('objects_count')
                    ->label('Objects')
                    ->counts('objects')
                    ->badge()
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_connected_at')
                    ->label('Last Connected')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([GridCsv::menu(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
