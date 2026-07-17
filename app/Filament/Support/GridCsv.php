<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reusable CSV Export / Import header actions for any Filament grid, wired globally via
 * Table::configureUsing (see AppServiceProvider). Export streams the filtered query's raw
 * columns (memory-safe, chunked). Import upserts the model's fillable columns by primary key
 * — a raw admin fallback; transactional tables (addresses, invoices) have dedicated pipelines.
 */
class GridCsv
{
    private const IMPORT_ROW_CAP = 20000;

    public static function exportAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn ($livewire): bool => static::eloquentQuery($livewire) !== null)
            ->action(function ($livewire): ?StreamedResponse {
                $query = static::eloquentQuery($livewire);
                if (! $query) {
                    Notification::make()->title('Export is not available for this view')->warning()->send();

                    return null;
                }

                $model = $query->getModel();
                $columns = Schema::getColumnListing($model->getTable());
                $key = $model->getKeyName();
                $filename = class_basename($model).'_'.now()->format('Ymd_His').'.csv';

                return response()->streamDownload(function () use ($query, $columns, $key): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, $columns);
                    $query->toBase()->orderBy($key)->chunk(1000, function ($rows) use ($out, $columns): void {
                        foreach ($rows as $row) {
                            fputcsv($out, array_map(fn (string $c) => $row->{$c} ?? '', $columns));
                        }
                    });
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv']);
            });
    }

    public static function importAction(): Action
    {
        return Action::make('importCsv')
            ->label('Import CSV')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn ($livewire): bool => static::eloquentQuery($livewire) !== null)
            ->schema([
                FileUpload::make('file')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->storeFiles(false)
                    ->required()
                    ->helperText('Header row must match column names. A row with a primary-key value updates that record; rows without one are created. Only editable columns are applied.'),
            ])
            ->action(function (array $data, $livewire): void {
                $model = static::eloquentQuery($livewire)?->getModel();
                $file = $data['file'] ?? null;
                if (! $model || ! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                $modelClass = $model::class;
                $fillable = $model->getFillable();
                $key = $model->getKeyName();

                $handle = fopen($file->getRealPath(), 'r');
                $headers = fgetcsv($handle) ?: [];
                $created = 0;
                $updated = 0;
                $failed = 0;
                $rows = 0;

                while (($line = fgetcsv($handle)) !== false) {
                    if (++$rows > self::IMPORT_ROW_CAP) {
                        break;
                    }
                    $assoc = @array_combine($headers, $line);
                    if ($assoc === false) {
                        $failed++;

                        continue;
                    }
                    $attrs = array_intersect_key($assoc, array_flip($fillable));
                    try {
                        $id = $assoc[$key] ?? null;
                        $existing = $id ? $modelClass::find($id) : null;
                        if ($existing) {
                            $existing->update($attrs);
                            $updated++;
                        } else {
                            $modelClass::create($attrs);
                            $created++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                    }
                }
                fclose($handle);

                Notification::make()
                    ->title('Import complete')
                    ->body("Created {$created}, updated {$updated}".($failed ? ", {$failed} skipped" : '').'.')
                    ->{$failed ? 'warning' : 'success'}()
                    ->send();
            });
    }

    /**
     * The table's filtered Eloquent query, or null for collection-backed / non-Eloquent tables.
     */
    protected static function eloquentQuery($livewire): ?Builder
    {
        if (! method_exists($livewire, 'getFilteredTableQuery')) {
            return null;
        }

        try {
            $query = $livewire->getFilteredTableQuery();
        } catch (\Throwable $e) {
            return null;
        }

        return $query instanceof Builder ? $query : null;
    }
}
