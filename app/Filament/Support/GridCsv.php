<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

/**
 * Reusable CSV Export / Import header actions for any Filament grid, wired globally via
 * Table::configureUsing (see AppServiceProvider). Export streams the filtered query's raw
 * columns (memory-safe, chunked). Import upserts the model's fillable columns by primary key
 * — a raw admin fallback; transactional tables (addresses, invoices) have dedicated pipelines.
 */
class GridCsv
{
    private const IMPORT_ROW_CAP = 20000;

    /**
     * The import + export actions registered (hidden) on every grid so they're mountable.
     * They render nothing themselves; the visible trigger is rendered inline in the toolbar
     * by renderTrigger() (via a render hook). Registered as header actions because those
     * survive a resource's toolbarActions() reset — and hidden, so no header row appears.
     * mountAction() doesn't check visibility, so a hidden action still mounts.
     *
     * @return array<int, Action>
     */
    public static function registeredActions(): array
    {
        return [
            static::importAction()->hidden(),
            static::exportAction()->hidden(),
        ];
    }

    /**
     * The visible Import/Export icon dropdown, rendered inline in the toolbar right cluster
     * (next to the Filters / column-manager triggers) via a render hook. Its items mount the
     * hidden actions registered above. Empty on non-Eloquent grids.
     */
    public static function renderTrigger(): string
    {
        $livewire = Livewire::current();
        if (! $livewire || static::eloquentQuery($livewire) === null) {
            return '';
        }

        return Blade::render(<<<'BLADE'
            <x-filament::dropdown placement="bottom-end">
                <x-slot name="trigger">
                    <x-filament::icon-button
                        icon="heroicon-m-arrows-right-left"
                        color="gray"
                        label="Import / Export"
                        tooltip="Import / Export"
                    />
                </x-slot>
                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item icon="heroicon-m-arrow-up-tray" wire:click="mountTableAction('importCsv')">
                        Import CSV
                    </x-filament::dropdown.list.item>
                    <x-filament::dropdown.list.item icon="heroicon-m-arrow-down-tray" wire:click="mountTableAction('exportCsv')">
                        Export CSV
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        BLADE);
    }

    public static function exportAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn ($livewire): bool => static::eloquentQuery($livewire) !== null)
            // Write the FILTERED query to a storage file and hand back a normal download link (in a toast
            // and the notification bell). A Livewire action's streamDownload doesn't reliably reach the
            // browser — spinner finishes, no file — so we deliver via a real GET route instead.
            ->action(function ($livewire): void {
                $query = static::eloquentQuery($livewire);
                if (! $query) {
                    Notification::make()->title('Export is not available for this view')->warning()->send();

                    return;
                }

                $model = $query->getModel();
                $columns = Schema::getColumnListing($model->getTable());
                $key = $model->getKeyName();
                $filename = class_basename($model).'_'.now()->format('Ymd_His').'_'.Str::random(6).'.csv';

                Storage::disk('local')->makeDirectory('exports');
                $path = Storage::disk('local')->path('exports/'.$filename);
                $out = fopen($path, 'w');
                fputcsv($out, $columns);
                $rowCount = 0;
                $query->toBase()->orderBy($key)->chunk(1000, function ($rows) use ($out, $columns, &$rowCount): void {
                    foreach ($rows as $row) {
                        fputcsv($out, array_map(fn (string $c) => $row->{$c} ?? '', $columns));
                        $rowCount++;
                    }
                });
                fclose($out);

                $download = NotificationAction::make('download')
                    ->label('Download CSV')
                    ->url(route('grid-export.download', ['file' => $filename]))
                    ->markAsRead();

                // Toast now + the bell entry, both with the download link.
                Notification::make()
                    ->title('Export ready')
                    ->body(number_format($rowCount).' row'.($rowCount === 1 ? '' : 's').' (current filters).')
                    ->success()
                    ->actions([$download])
                    ->send();

                if ($user = Filament::auth()->user()) {
                    Notification::make()
                        ->title('Export ready')
                        ->body(class_basename($model).' — '.number_format($rowCount).' rows.')
                        ->success()
                        ->actions([$download])
                        ->sendToDatabase($user);
                }
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
