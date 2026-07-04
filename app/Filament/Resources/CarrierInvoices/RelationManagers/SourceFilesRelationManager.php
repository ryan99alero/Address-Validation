<?php

namespace App\Filament\Resources\CarrierInvoices\RelationManagers;

use App\Models\CarrierImportFile;
use App\Services\Invoices\SourceFileFetcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SourceFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceFiles';

    protected static ?string $title = 'Source Files';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('filename')
                    ->label('File')
                    ->icon(fn (CarrierImportFile $record): string => strtolower(pathinfo((string) $record->filename, PATHINFO_EXTENSION)) === 'pdf'
                        ? 'heroicon-o-document'
                        : 'heroicon-o-table-cells')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('imported_at')
                    ->label('Imported')
                    ->dateTime('M j, Y g:i A'),
                TextColumn::make('invoice_count')
                    ->label('Invoices in file')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('source_reference')
                    ->label('Source path')
                    ->wrap()
                    ->copyable()
                    ->tooltip(fn (CarrierImportFile $record): ?string => $record->source_reference)
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (CarrierImportFile $record): ?StreamedResponse {
                        try {
                            $result = app(SourceFileFetcher::class)->toLocalPath($record);
                        } catch (\Throwable $e) {
                            Notification::make()->title('Download failed')->body($e->getMessage())->danger()->send();

                            return null;
                        }

                        return response()->streamDownload(function () use ($result): void {
                            $handle = fopen($result['path'], 'rb');
                            if ($handle !== false) {
                                fpassthru($handle);
                                fclose($handle);
                            }
                            if ($result['cleanup']) {
                                @unlink($result['path']);
                            }
                        }, $record->filename);
                    }),
            ])
            ->paginated(false);
    }
}
