<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

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
        // CSS-hidden (NOT ->hidden()): Filament v5 will not RESOLVE a ->hidden() action for mounting,
        // so mountTableAction('exportCsv') from the ⇄ dropdown silently no-ops. extraAttributes keeps
        // the action fully mountable while the button itself is display:none (the visible trigger is the
        // dropdown rendered by renderTrigger()).
        return [
            static::importAction()->extraAttributes(['class' => 'hidden']),
            static::exportAction()->extraAttributes(['class' => 'hidden']),
            static::exportXlsxAction()->extraAttributes(['class' => 'hidden']),
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
                    <x-filament::dropdown.list.item icon="heroicon-m-table-cells" wire:click="mountTableAction('exportXlsx')">
                        Export Excel (.xlsx)
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        BLADE);
    }

    public static function exportAction(): Action
    {
        return static::makeExportAction('exportCsv', 'Export CSV', 'csv');
    }

    public static function exportXlsxAction(): Action
    {
        return static::makeExportAction('exportXlsx', 'Export Excel', 'xlsx');
    }

    protected static function makeExportAction(string $name, string $label, string $format): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn ($livewire): bool => static::eloquentQuery($livewire) !== null)
            ->action(fn ($livewire) => static::runExport($livewire, $format));
    }

    /**
     * Write the FILTERED query to a storage file and hand back a real download link (a toast + the
     * notification bell). A Livewire action's streamDownload doesn't reliably reach the browser, so we
     * deliver via a normal GET route instead. Both formats stream row-by-row from a chunked query, so a
     * large filtered export stays memory-safe.
     */
    protected static function runExport($livewire, string $format): void
    {
        $query = static::eloquentQuery($livewire);
        if (! $query) {
            Notification::make()->title('Export is not available for this view')->warning()->send();

            return;
        }

        $model = $query->getModel();
        $columns = Schema::getColumnListing($model->getTable());
        $key = $model->getKeyName();
        $ext = $format === 'xlsx' ? 'xlsx' : 'csv';
        $filename = class_basename($model).'_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$ext;

        Storage::disk('local')->makeDirectory('exports');
        $path = Storage::disk('local')->path('exports/'.$filename);

        $rowCount = $format === 'xlsx'
            ? static::writeXlsx($query, $columns, $key, $path)
            : static::writeCsv($query, $columns, $key, $path);

        $download = Action::make('download')
            ->label('Download '.strtoupper($ext))
            ->url(route('grid-export.download', ['file' => $filename]));

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
    }

    /**
     * @param  array<int, string>  $columns
     */
    protected static function writeCsv(Builder $query, array $columns, string $key, string $path): int
    {
        $out = fopen($path, 'w');
        fputcsv($out, $columns);
        $count = 0;
        $query->toBase()->orderBy($key)->chunk(1000, function ($rows) use ($out, $columns, &$count): void {
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $c) => $row->{$c} ?? '', $columns));
                $count++;
            }
        });
        fclose($out);

        return $count;
    }

    /**
     * @param  array<int, string>  $columns
     */
    protected static function writeXlsx(Builder $query, array $columns, string $key, string $path): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($columns));
        $count = 0;
        $query->toBase()->orderBy($key)->chunk(1000, function ($rows) use ($writer, $columns, &$count): void {
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn (string $c) => $row->{$c} ?? '', $columns)));
                $count++;
            }
        });
        $writer->close();

        return $count;
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
     * Filament v5 does NOT inject the Livewire component into a HIDDEN action's closure (the passed
     * $livewire is null when the export runs via mountTableAction), so fall back to Livewire::current()
     * — the component currently handling the request — exactly as renderTrigger() already does.
     */
    protected static function eloquentQuery($livewire = null): ?Builder
    {
        if (! is_object($livewire) || ! method_exists($livewire, 'getFilteredTableQuery')) {
            $livewire = Livewire::current();
        }

        if (! is_object($livewire) || ! method_exists($livewire, 'getFilteredTableQuery')) {
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
