<?php

namespace App\Filament\Resources\FolderIntegrations\Tables;

use App\Jobs\ProcessFolderIntegration;
use App\Models\FolderIntegration;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FolderIntegrationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('carrier.name')->label('Carrier')->badge()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('connection_type')->label('Type')->badge(),
                TextColumn::make('base_path')->label('Path')->limit(50)->wrap(),
                TextColumn::make('last_processed_at')->label('Last Run')->since()->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('scanNow')
                    ->label('Scan Now')
                    ->icon('heroicon-o-folder-arrow-down')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Scan folder for invoices')
                    ->modalDescription('Queues a background scan: imports new invoice files (deduped by content) and parses fees. Scanning the network share over many years can take minutes — it runs on the queue worker, not here. Watch the "Last Run" column / logs.')
                    ->schema([
                        TextInput::make('limit')
                            ->label('Max files this run (0 = all)')
                            ->numeric()
                            ->default(25),
                    ])
                    ->action(function (FolderIntegration $record, array $data): void {
                        $limit = (int) ($data['limit'] ?? 0);
                        $base = rtrim($record->base_path, '/');

                        if (! is_dir($base)) {
                            Notification::make()->title('Folder not found')
                                ->body("Cannot access: {$base}")->danger()->persistent()->send();

                            return;
                        }

                        // One short, resilient job per year sub-folder (falls back to
                        // the whole folder if there are no sub-folders).
                        $subFolders = glob($base.'/*', GLOB_ONLYDIR) ?: [];
                        if (empty($subFolders)) {
                            ProcessFolderIntegration::dispatch($record, $limit);
                            $count = 1;
                        } else {
                            foreach ($subFolders as $folder) {
                                ProcessFolderIntegration::dispatch($record, $limit, $folder);
                            }
                            $count = count($subFolders);
                        }

                        Notification::make()
                            ->title('Scan queued')
                            ->body("Queued {$count} scan job(s) (one per year folder) on the queue worker. \"Last Run\" updates as they finish. A queue worker must be running.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
