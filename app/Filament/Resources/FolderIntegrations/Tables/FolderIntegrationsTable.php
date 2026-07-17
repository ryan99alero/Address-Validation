<?php

namespace App\Filament\Resources\FolderIntegrations\Tables;

use App\Filament\Support\GridCsv;
use App\Jobs\ProcessFolderIntegration;
use App\Models\FolderIntegration;
use App\Services\Invoices\SmbInvoiceReader;
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

                        // SMB scans the whole share recursively in one resilient job —
                        // no local directory checks / per-subfolder chunking.
                        if ($record->connection_type === FolderIntegration::TYPE_SMB) {
                            ProcessFolderIntegration::dispatch($record, $limit);

                            Notification::make()
                                ->title('Scan queued')
                                ->body('Queued an SMB scan on the queue worker. "Last Run" updates when it finishes. A queue worker must be running.')
                                ->success()
                                ->send();

                            return;
                        }

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
                Action::make('testConnection')
                    ->label('Test')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->action(function (FolderIntegration $record): void {
                        try {
                            if ($record->connection_type === FolderIntegration::TYPE_SMB) {
                                $count = app(SmbInvoiceReader::class)->testConnection($record);
                                $body = "Connected to \\\\{$record->smb_host}\\{$record->smb_share} — {$count} item(s) in the base folder.";
                            } elseif (is_dir((string) $record->base_path)) {
                                $body = 'Folder path is accessible.';
                            } else {
                                throw new \RuntimeException("Folder not found: {$record->base_path}");
                            }

                            $record->markChecked('ok');
                            Notification::make()->title('Connection OK')->body($body)->success()->send();
                        } catch (\Throwable $e) {
                            $record->markChecked('error', $e->getMessage());
                            Notification::make()->title('Connection failed')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([GridCsv::menu(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
