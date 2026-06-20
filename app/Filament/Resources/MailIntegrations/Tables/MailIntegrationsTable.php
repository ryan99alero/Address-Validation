<?php

namespace App\Filament\Resources\MailIntegrations\Tables;

use App\Models\MailIntegration;
use App\Services\Mail\InvoiceMailFetchService;
use App\Services\Mail\InvoiceMailProcessService;
use App\Services\Mail\MailboxService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MailIntegrationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('carrier_detection')
                    ->label('Carrier By')
                    ->badge()
                    ->formatStateUsing(fn (MailIntegration $record): string => match ($record->carrier_detection) {
                        'sender_domain' => 'Sender domain',
                        'file_content' => 'File content',
                        'fixed' => 'Fixed: '.($record->carrier?->name ?? '—'),
                        default => $record->carrier_detection ?? '—',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('imap_host')
                    ->label('Host')
                    ->description(fn (MailIntegration $record): string => $record->imap_username),
                TextColumn::make('last_status')
                    ->label('Last Check')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'ok' => 'success',
                        'error' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Never')
                    ->description(fn (MailIntegration $record): ?string => $record->last_error
                        ? Str::limit($record->last_error, 80)
                        : $record->last_checked_at?->diffForHumans())
                    ->tooltip(fn (MailIntegration $record): ?string => $record->last_error),
            ])
            ->recordActions([
                Action::make('testConnection')
                    ->label('Test')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->action(function (MailIntegration $record): void {
                        $result = app(MailboxService::class)->testConnection($record);

                        $notification = Notification::make()
                            ->title($result['ok'] ? 'Connection OK' : 'Connection Failed')
                            ->body($result['message']);

                        if ($result['ok']) {
                            $notification->success()->send();
                        } else {
                            // Persist so the full error (with available folders) stays readable.
                            $notification->danger()->persistent()->send();
                        }
                    }),
                Action::make('serverInfo')
                    ->label('Server Info')
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->action(function (MailIntegration $record): void {
                        try {
                            $caps = app(MailboxService::class)->capabilities($record);
                            $has = fn (string $c): string => in_array($c, $caps, true) ? 'YES' : 'no';

                            Notification::make()
                                ->title('IMAP server capabilities')
                                ->body(
                                    'MOVE: '.$has('MOVE').'  |  UIDPLUS: '.$has('UIDPLUS').
                                    "\n\nAll: ".implode(', ', $caps)
                                )
                                ->info()
                                ->persistent()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not read server info')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('fetchNow')
                    ->label('Fetch (Test)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Fetch invoice files (test)')
                    ->modalDescription('Downloads and unzips invoice attachments to the local hold folder for inspection. Your emails are not moved, deleted, or marked as read, and nothing is parsed or archived.')
                    ->action(function (MailIntegration $record): void {
                        try {
                            $stats = app(InvoiceMailFetchService::class)->fetch($record);

                            $body = "Scanned {$stats['messages']} message(s), {$stats['attachments']} matching attachment(s), extracted {$stats['files']} file(s).";
                            if (! empty($stats['errors'])) {
                                $body .= ' Errors: '.implode('; ', $stats['errors']);
                            }

                            $notification = Notification::make()
                                ->title('Fetch complete')
                                ->body($body)
                                ->persistent();

                            empty($stats['errors']) ? $notification->success()->send() : $notification->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fetch failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('processNow')
                    ->label('Process Now')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Process invoices now')
                    ->modalDescription('Fetches new (unread) invoice emails, parses corrections into the cache, archives each PDF to Carrier/Year/Month, then marks the emails read (and moves them if a processed folder is set).')
                    ->action(function (MailIntegration $record): void {
                        try {
                            $stats = app(InvoiceMailProcessService::class)->process($record);

                            $body = "Processed {$stats['messages']} email(s): {$stats['invoices']} invoice(s), {$stats['corrections']} correction(s), {$stats['skipped']} duplicate(s) skipped.";
                            if (! empty($stats['errors'])) {
                                $body .= ' Errors: '.implode('; ', $stats['errors']);
                            }
                            if (! empty($stats['mail_warnings'])) {
                                $body .= ' Note (emails still processed): '.implode('; ', array_unique($stats['mail_warnings']));
                            }

                            $notification = Notification::make()
                                ->title('Processing complete')
                                ->body($body)
                                ->persistent();

                            (empty($stats['errors']) && empty($stats['mail_warnings']))
                                ? $notification->success()->send()
                                : $notification->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Processing failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
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
