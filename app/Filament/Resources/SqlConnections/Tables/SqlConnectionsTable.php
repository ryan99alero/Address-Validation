<?php

namespace App\Filament\Resources\SqlConnections\Tables;

use App\Filament\Support\GridCsv;
use App\Models\SqlConnection;
use App\Services\ShippingDatabaseService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SqlConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('purpose')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => SqlConnection::purposes()[$state] ?? ($state ?? '—'))
                    ->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('driver')->badge()->color('gray'),
                TextColumn::make('host')->placeholder('—'),
                TextColumn::make('database')->placeholder('—')->toggleable(),
                TextColumn::make('last_test_status')
                    ->label('Last Test')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'ok' ? 'success' : ($state === 'failed' ? 'danger' : 'gray'))
                    ->placeholder('untested'),
                TextColumn::make('last_tested_at')->label('Tested')->dateTime('M j, Y g:i A')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-bolt')
                    ->color('gray')
                    ->action(function (SqlConnection $record): void {
                        $result = app(ShippingDatabaseService::class)->testConnectionDetailed($record);

                        $record->update([
                            'last_tested_at' => now(),
                            'last_test_status' => $result['ok'] ? 'ok' : 'failed',
                        ]);

                        Notification::make()
                            ->title($result['ok'] ? 'Connection successful' : 'Connection failed')
                            ->body($result['message'])
                            ->status($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),
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
