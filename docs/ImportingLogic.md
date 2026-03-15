# Import/Export System Documentation

This document provides comprehensive instructions for implementing a Filament-based import/export system with column mapping wizard functionality.

---

## Table of Contents

1. [Technology Stack](#technology-stack)
2. [Package Dependencies](#package-dependencies)
3. [Architecture Overview](#architecture-overview)
4. [ExcelImportAction Implementation](#excelimportaction-implementation)
5. [Importer Class Pattern](#importer-class-pattern)
6. [Exporter Class Pattern](#exporter-class-pattern)
7. [CSS Configuration](#css-configuration)
8. [Troubleshooting](#troubleshooting)
9. [Future Enhancement: Template Saving](#future-enhancement-template-saving)

---

## Technology Stack

### Backend
| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.4+ | Server-side language |
| Laravel | 12.x | PHP Framework |
| Filament | 4.x | Admin panel & SDUI framework |
| Livewire | 3.x | Reactive components |

### Frontend
| Technology | Version | Purpose |
|------------|---------|---------|
| Tailwind CSS | 4.x | Utility-first CSS |
| Alpine.js | 3.x | Lightweight JS (bundled with Livewire) |
| Vite | 6.x | Asset bundling |

---

## Package Dependencies

### Composer Packages (composer.json)

```json
{
    "require": {
        "php": "^8.2",
        "filament/filament": "^4.7",
        "laravel/framework": "^12.0",
        "livewire/livewire": "^3.5",
        "maatwebsite/excel": "^3.1",
        "doctrine/dbal": "^4.2",
        "spatie/laravel-permission": "^6.0",
        "bezhansalleh/filament-shield": "^4.1",
        "blade-ui-kit/blade-heroicons": "^2.5",
        "blade-ui-kit/blade-icons": "^1.7"
    },
    "require-dev": {
        "laravel/pint": "^1.13",
        "pestphp/pest": "^3.5",
        "pestphp/pest-plugin-laravel": "^3.1",
        "barryvdh/laravel-debugbar": "^3.14"
    }
}
```

### Key Packages Explained

| Package | Purpose |
|---------|---------|
| `maatwebsite/excel` | Excel file parsing (XLSX, XLS, CSV) - provides PhpSpreadsheet |
| `filament/filament` | Admin panel with built-in ImportAction |
| `doctrine/dbal` | Database abstraction for migrations (column modifications) |
| `spatie/laravel-permission` | Role/permission management |
| `bezhansalleh/filament-shield` | Filament integration for permissions |

### NPM Packages (package.json)

```json
{
    "devDependencies": {
        "@tailwindcss/forms": "^0.5.10",
        "@tailwindcss/postcss": "^4.1.18",
        "@tailwindcss/typography": "^0.5.16",
        "autoprefixer": "^10.4.21",
        "laravel-vite-plugin": "^1.0",
        "postcss": "^8.5.6",
        "postcss-nesting": "^13.0.2",
        "tailwindcss": "^4.1.18",
        "vite": "^6.3.6"
    }
}
```

---

## Architecture Overview

### Import Flow

```
User uploads file
       ↓
ExcelImportAction receives file
       ↓
If XLSX/XLS → Convert to CSV stream (PhpSpreadsheet)
       ↓
Parse CSV headers
       ↓
Auto-match columns to importer fields (case-insensitive)
       ↓
Display column mapping wizard UI
       ↓
User confirms/adjusts mappings
       ↓
Create Import record in database
       ↓
Dispatch queued ImportJob batches
       ↓
Importer processes rows:
  - beforeValidate()
  - resolveRecord()
  - beforeCreate() / beforeSave()
  - save()
       ↓
Send completion notification
```

### File Structure

```
app/
├── Filament/
│   ├── Actions/
│   │   └── ExcelImportAction.php      # Custom action extending ImportAction
│   ├── Imports/
│   │   └── YourModelImporter.php      # Importer class
│   ├── Exports/
│   │   ├── YourModelExporter.php      # Exporter class
│   │   └── Components/
│   │       └── ExportColumn.php       # Custom export column with snake_case headers
│   └── Resources/
│       └── YourModelResource/
│           └── Pages/
│               └── ListYourModels.php # Has import/export header actions
```

---

## ExcelImportAction Implementation

Create `app/Filament/Actions/ExcelImportAction.php`:

```php
<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\Events\ImportCompleted;
use Filament\Actions\Imports\Events\ImportStarted;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\View\ActionsIconAlias;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\ChunkIterator;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\Bom;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use League\Csv\Writer;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Extended ImportAction that supports XLSX and XLS files in addition to CSV.
 * Converts Excel files to CSV on-the-fly before processing.
 */
class ExcelImportAction extends ImportAction
{
    /**
     * Get accepted file types including Excel formats.
     */
    protected function getAcceptedFileTypes(): array
    {
        return [
            'text/csv',
            'text/x-csv',
            'application/csv',
            'application/x-csv',
            'text/comma-separated-values',
            'text/x-comma-separated-values',
            'text/plain',
            'application/vnd.ms-excel', // xls
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        ];
    }

    protected function setUp(): void
    {
        // Call grandparent setup (Action), not parent (ImportAction)
        Action::setUp();

        $this->label(fn (ExcelImportAction $action): string => __('filament-actions::import.label', ['label' => $action->getPluralModelLabel()]));

        $this->modalHeading(fn (ExcelImportAction $action): string => __('filament-actions::import.modal.heading', ['label' => $action->getTitleCasePluralModelLabel()]));

        $this->modalDescription(fn (ExcelImportAction $action): mixed => $action->getModalAction('downloadExample'));

        $this->modalSubmitActionLabel(__('filament-actions::import.modal.actions.import.label'));

        $this->groupedIcon(FilamentIcon::resolve(ActionsIconAlias::IMPORT_ACTION_GROUPED) ?? Heroicon::ArrowUpTray);

        $this->schema(fn (ExcelImportAction $action): array => array_merge([
            FileUpload::make('file')
                ->label(__('filament-actions::import.modal.form.file.label'))
                ->placeholder(__('filament-actions::import.modal.form.file.placeholder'))
                ->acceptedFileTypes($this->getAcceptedFileTypes())
                ->rules($action->getFileValidationRules())
                ->afterStateUpdated(function (FileUpload $component, Component $livewire, Set $set, ?TemporaryUploadedFile $state) use ($action): void {
                    if (! $state instanceof TemporaryUploadedFile) {
                        return;
                    }

                    try {
                        $livewire->validateOnly($component->getStatePath());
                    } catch (ValidationException $exception) {
                        $component->state([]);
                        throw $exception;
                    }

                    $csvStream = $this->getUploadedFileStream($state);

                    if (! $csvStream) {
                        return;
                    }

                    $csvReader = CsvReader::from($csvStream);

                    if (filled($csvDelimiter = $this->getCsvDelimiter($csvReader))) {
                        $csvReader->setDelimiter($csvDelimiter);
                    }

                    $csvReader->setHeaderOffset($action->getHeaderOffset() ?? 0);

                    $csvColumns = $csvReader->getHeader();

                    // Auto-match columns (case-insensitive)
                    $lowercaseCsvColumnValues = array_map(Str::lower(...), $csvColumns);
                    $lowercaseCsvColumnKeys = array_combine(
                        $lowercaseCsvColumnValues,
                        $csvColumns,
                    );

                    $set('columnMap', array_reduce($action->getImporter()::getColumns(), function (array $carry, ImportColumn $column) use ($lowercaseCsvColumnKeys, $lowercaseCsvColumnValues) {
                        $carry[$column->getName()] = $lowercaseCsvColumnKeys[
                            Arr::first(
                                array_intersect(
                                    $lowercaseCsvColumnValues,
                                    $column->getGuesses(),
                                ),
                            )
                        ] ?? null;

                        return $carry;
                    }, []));
                })
                ->storeFiles(false)
                ->visibility('private')
                ->required()
                ->hiddenLabel(),

            // Column mapping wizard UI
            Fieldset::make(__('filament-actions::import.modal.form.columns.label'))
                ->columns(1)
                ->inlineLabel()
                ->schema(function (Get $get) use ($action): array {
                    $csvFile = $get('file');

                    if (! $csvFile instanceof TemporaryUploadedFile) {
                        return [];
                    }

                    $csvStream = $this->getUploadedFileStream($csvFile);

                    if (! $csvStream) {
                        return [];
                    }

                    $csvReader = CsvReader::from($csvStream);

                    if (filled($csvDelimiter = $this->getCsvDelimiter($csvReader))) {
                        $csvReader->setDelimiter($csvDelimiter);
                    }

                    $csvReader->setHeaderOffset($action->getHeaderOffset() ?? 0);

                    $csvColumns = $csvReader->getHeader();
                    $csvColumnOptions = array_combine($csvColumns, $csvColumns);

                    return array_map(
                        fn (ImportColumn $column): Select => $column->getSelect()->options($csvColumnOptions),
                        $action->getImporter()::getColumns(),
                    );
                })
                ->statePath('columnMap')
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),
        ], $action->getImporter()::getOptionsFormComponents()));

        // ... rest of setUp() handles action processing, batching, notifications
        // See full implementation in app/Filament/Actions/ExcelImportAction.php
    }

    /**
     * Override to allow xlsx, xls, and csv extensions.
     */
    public function getFileValidationRules(): array
    {
        $rules = [];
        $rules[] = 'extensions:csv,txt,xlsx,xls';

        // Add duplicate column check
        $rules[] = fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $csvStream = $this->getUploadedFileStream($value);

            if (! $csvStream) {
                return;
            }

            $csvReader = CsvReader::from($csvStream);

            if (filled($csvDelimiter = $this->getCsvDelimiter($csvReader))) {
                $csvReader->setDelimiter($csvDelimiter);
            }

            $csvReader->setHeaderOffset($this->getHeaderOffset() ?? 0);

            $csvColumns = $csvReader->getHeader();
            $duplicateCsvColumns = [];

            foreach (array_count_values($csvColumns) as $header => $count) {
                if ($count <= 1) {
                    continue;
                }
                $duplicateCsvColumns[] = $header;
            }

            if (empty($duplicateCsvColumns)) {
                return;
            }

            $filledDuplicateCsvColumns = array_filter($duplicateCsvColumns, fn ($value): bool => filled($value));

            $fail(trans_choice('filament-actions::import.modal.form.file.rules.duplicate_columns', count($filledDuplicateCsvColumns), [
                'columns' => implode(', ', $filledDuplicateCsvColumns),
            ]));
        };

        // Add any file rules from fileRules() calls
        foreach ($this->fileValidationRules ?? [] as $fileRules) {
            $fileRules = $this->evaluate($fileRules);

            if (is_string($fileRules)) {
                $fileRules = explode('|', $fileRules);
            }

            $rules = [...$rules, ...$fileRules];
        }

        return $rules;
    }

    /**
     * Handle file stream - convert Excel to CSV if needed.
     *
     * @return resource|false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // If it's an Excel file, convert to CSV first
        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->convertExcelToStream($file);
        }

        // For CSV files, use parent implementation
        return parent::getUploadedFileStream($file);
    }

    /**
     * Convert Excel file to CSV and return as stream.
     *
     * @return resource|false
     */
    protected function convertExcelToStream(TemporaryUploadedFile $file)
    {
        $originalPath = $file->getRealPath();

        // Load the spreadsheet using PhpSpreadsheet
        $spreadsheet = IOFactory::load($originalPath);

        // Create CSV writer
        $csvWriter = new Csv($spreadsheet);
        $csvWriter->setDelimiter(',');
        $csvWriter->setEnclosure('"');
        $csvWriter->setLineEnding("\n");
        $csvWriter->setSheetIndex(0); // Use first sheet

        // Write to temp file
        $tempCsvPath = sys_get_temp_dir() . '/' . uniqid('excel_import_') . '.csv';
        $csvWriter->save($tempCsvPath);

        // Clean up spreadsheet memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Open and return stream
        return fopen($tempCsvPath, 'r');
    }
}
```

---

## Importer Class Pattern

Create `app/Filament/Imports/YourModelImporter.php`:

```php
<?php

namespace App\Filament\Imports;

use App\Models\YourModel;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

class YourModelImporter extends Importer
{
    protected static ?string $model = YourModel::class;

    public static function getColumns(): array
    {
        return [
            // ID column - used for resolving existing records, but never filled
            ImportColumn::make('id')
                ->rules(['nullable', 'integer'])
                ->fillRecordUsing(fn () => null), // Prevents setting id=null on existing records

            // External system identifier
            ImportColumn::make('external_id')
                ->rules(['nullable', 'string', 'max:255']),

            // Foreign key with validation
            ImportColumn::make('department_id')
                ->rules(['nullable', 'integer', 'exists:departments,id']),

            // Email with validation
            ImportColumn::make('email')
                ->rules(['nullable', 'email', 'max:255']),

            // Required fields (validated conditionally in beforeValidate)
            ImportColumn::make('first_name')
                ->rules(['nullable', 'string', 'max:50']),

            ImportColumn::make('last_name')
                ->rules(['nullable', 'string', 'max:50']),

            // Boolean fields
            ImportColumn::make('is_active')
                ->boolean()
                ->rules(['nullable', 'boolean']),

            // Date fields
            ImportColumn::make('date_of_hire')
                ->rules(['nullable', 'date']),

            // Enum fields (normalize case in beforeValidate)
            ImportColumn::make('status')
                ->rules(['nullable', 'in:active,inactive,pending']),

            // Numeric fields
            ImportColumn::make('pay_rate')
                ->rules(['nullable', 'numeric', 'min:0']),
        ];
    }

    /**
     * Find or create the record being imported.
     * Check multiple unique identifiers for matching.
     */
    public function resolveRecord(): ?YourModel
    {
        // If ID is provided, try to find existing record for update
        if (! empty($this->data['id'])) {
            $record = YourModel::find($this->data['id']);
            if ($record) {
                return $record;
            }
        }

        // Try to find by external_id
        if (! empty($this->data['external_id'])) {
            $record = YourModel::where('external_id', $this->data['external_id'])->first();
            if ($record) {
                return $record;
            }
        }

        // Try to find by email
        if (! empty($this->data['email'])) {
            $record = YourModel::where('email', $this->data['email'])->first();
            if ($record) {
                return $record;
            }
        }

        // Otherwise create new
        return new YourModel;
    }

    /**
     * Hook to modify/validate data BEFORE Filament validation runs.
     * Use for: normalizing case, removing empty values, conditional validation.
     */
    protected function beforeValidate(): void
    {
        // Remove empty id from data - prevents "Column 'id' cannot be null"
        if (array_key_exists('id', $this->data) && ($this->data['id'] === '' || $this->data['id'] === null)) {
            unset($this->data['id']);
        }

        // Normalize enum fields to lowercase
        if (! empty($this->data['status'])) {
            $this->data['status'] = strtolower(trim($this->data['status']));
        }

        // Conditional validation example
        $status = $this->data['status'] ?? null;

        if ($status !== 'pending') {
            $errors = [];

            if (empty($this->data['first_name']) || trim($this->data['first_name']) === '') {
                $errors['first_name'] = 'The first name field is required.';
            }
            if (empty($this->data['last_name']) || trim($this->data['last_name']) === '') {
                $errors['last_name'] = 'The last name field is required.';
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }
        }
    }

    /**
     * Hook called before a NEW record is saved.
     * Use for: setting default values, created_by tracking.
     */
    protected function beforeCreate(): void
    {
        // IMPORTANT: Use $this->import->user_id, NOT auth()->id()
        // Auth is not available in queued job context
        $this->record->created_by = $this->import->user_id;

        // Default boolean fields that cannot be null
        if (! isset($this->data['is_active']) || $this->data['is_active'] === '' || $this->data['is_active'] === null) {
            $this->record->is_active = true;
        }

        // Default other required fields
        if (! isset($this->data['pay_type']) || $this->data['pay_type'] === '') {
            $this->record->pay_type = 'hourly';
        }
    }

    /**
     * Hook called before ANY record (new or existing) is saved.
     * Use for: updated_by tracking, final data normalization.
     */
    protected function beforeSave(): void
    {
        // IMPORTANT: Use $this->import->user_id, NOT auth()->id()
        $this->record->updated_by = $this->import->user_id;

        // Final normalization - ensure enum case is correct
        if (! empty($this->record->status)) {
            $this->record->status = strtolower(trim($this->record->status));
        }

        // Ensure NOT NULL boolean fields never get set to null
        if ($this->record->is_active === null) {
            $this->record->is_active = true;
        }
    }

    /**
     * Notification shown when import completes.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your import has completed and ' . Number::format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
}
```

### Key Importer Patterns

#### 1. ID Column Handling
```php
ImportColumn::make('id')
    ->rules(['nullable', 'integer'])
    ->fillRecordUsing(fn () => null), // Never fill - only for resolving
```

#### 2. Remove Empty ID in beforeValidate
```php
if (array_key_exists('id', $this->data) && ($this->data['id'] === '' || $this->data['id'] === null)) {
    unset($this->data['id']);
}
```

#### 3. Case Normalization for Enums
```php
// CSV may have "Agency" but DB expects "agency"
$this->data['temp_type'] = strtolower(trim($this->data['temp_type']));
```

#### 4. Queued Job Auth Context
```php
// WRONG - auth() is null in queue context
$this->record->created_by = auth()->id();

// CORRECT - use import's user_id
$this->record->created_by = $this->import->user_id;
```

---

## Exporter Class Pattern

### Custom ExportColumn

Create `app/Filament/Exports/Components/ExportColumn.php`:

```php
<?php

namespace App\Filament\Exports\Components;

use Filament\Actions\Exports\ExportColumn as BaseExportColumn;

/**
 * Custom ExportColumn that defaults the label to the snake_case column name.
 * This ensures exported headers match import field names for seamless reimport.
 */
class ExportColumn extends BaseExportColumn
{
    public static function make(?string $name = null): static
    {
        $column = parent::make($name);

        // Default label to the column name (snake_case) for import compatibility
        if ($name !== null) {
            $column->label($name);
        }

        return $column;
    }
}
```

### Exporter Implementation

Create `app/Filament/Exports/YourModelExporter.php`:

```php
<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Components\ExportColumn;
use App\Models\YourModel;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class YourModelExporter extends Exporter
{
    protected static ?string $model = YourModel::class;

    public static function getColumns(): array
    {
        return [
            // Direct columns - label defaults to snake_case name
            ExportColumn::make('id'),
            ExportColumn::make('external_id'),
            ExportColumn::make('first_name'),
            ExportColumn::make('last_name'),
            ExportColumn::make('email'),
            ExportColumn::make('department_id'),

            // Relationship columns - need custom label
            ExportColumn::make('department.name')
                ->label('Department Name'),

            // More direct columns
            ExportColumn::make('is_active'),
            ExportColumn::make('status'),
            ExportColumn::make('pay_rate'),
            ExportColumn::make('date_of_hire'),

            // Timestamps
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your export has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
```

---

## ListRecords Page with Import/Export

```php
<?php

namespace App\Filament\Resources\YourModelResource\Pages;

use App\Filament\Actions\ExcelImportAction;
use App\Filament\Exports\YourModelExporter;
use App\Filament\Imports\YourModelImporter;
use App\Filament\Resources\YourModelResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\Rules\File;

class ListYourModels extends ListRecords
{
    protected static string $resource = YourModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ExcelImportAction::make()
                ->importer(YourModelImporter::class)
                ->fileRules([
                    File::types(['csv', 'xlsx', 'xls'])->max(10240), // 10MB
                ]),
            ExportAction::make()
                ->exporter(YourModelExporter::class),
        ];
    }
}
```

---

## CSS Configuration

### postcss.config.js

```js
export default {
    plugins: {
        '@tailwindcss/postcss': {},
        autoprefixer: {},
    },
};
```

### tailwind.config.js

```js
import defaultTheme from 'tailwindcss/defaultTheme';
import preset from './vendor/filament/filament/tailwind.config.preset'

/** @type {import('tailwindcss').Config} */
export default {
    presets: [preset],  // CRITICAL: Include Filament's preset
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',  // CRITICAL: Include Filament views
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },
    plugins: [],
};
```

### resources/css/app.css

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

/* Fix mobile navigation scrolling for Filament */
@media (max-width: 768px) {
    .fi-sidebar,
    .fi-sidebar-nav,
    .fi-layout-sidebar,
    [data-sidebar],
    nav[aria-label="Navigation"],
    .fi-sidebar-content,
    .fi-sidebar-group-list {
        overflow-y: auto !important;
        overflow-x: hidden !important;
        max-height: 100vh !important;
        -webkit-overflow-scrolling: touch !important;
        position: relative !important;
    }

    .fi-sidebar-nav ul,
    .fi-sidebar-group ul {
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }

    aside[role="navigation"],
    .fi-layout aside {
        height: 100vh !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }

    body {
        -webkit-overflow-scrolling: touch !important;
    }
}
```

### vite.config.js

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
```

### CSS Not Rendering Fix

If CSS/styles are not showing correctly:

1. **Run build**: `npm run build`
2. **Clear view cache**: `php artisan view:clear`
3. **Check Filament panel theme**:

In `AdminPanelProvider.php`, you may need:
```php
->viteTheme('resources/css/app.css')
```

Or if using a custom theme:
```php
->viteTheme('resources/css/filament/admin/theme.css')
```

4. **Verify Filament preset in tailwind.config.js**:
```js
import preset from './vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    // ...
}
```

---

## Troubleshooting

### Common Import Errors

#### "Column 'X' cannot be null"
**Cause**: CSV has empty string which becomes null for NOT NULL column.
**Fix**: Handle in `beforeCreate()` / `beforeSave()`:
```php
if ($this->record->is_active === null) {
    $this->record->is_active = true;
}
```

#### "Field X is required" for optional records
**Cause**: Validation rules don't account for conditional requirements.
**Fix**: Use `beforeValidate()` for conditional validation:
```php
protected function beforeValidate(): void
{
    if ($this->data['type'] !== 'special') {
        if (empty($this->data['required_field'])) {
            throw ValidationException::withMessages([
                'required_field' => 'This field is required.',
            ]);
        }
    }
}
```

#### Case-sensitivity issues with enums
**Cause**: CSV has "Active" but DB expects "active".
**Fix**: Normalize in `beforeValidate()`:
```php
$this->data['status'] = strtolower(trim($this->data['status']));
```

#### Import job failures with "System error"
**Debug**: Check `storage/logs/laravel.log` for actual error.
**Common cause**: Using `auth()->id()` in importer (null in queue context).
**Fix**: Use `$this->import->user_id` instead.

### Queue Worker Issues

**CRITICAL**: Always restart queue workers after modifying importer classes:
```bash
php artisan queue:restart
```

Queue workers cache code at startup. Changes won't take effect until restart.

---

## Future Enhancement: Template Saving

To implement saved import templates that remember column mappings:

### 1. Create Migration

```php
Schema::create('import_templates', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('importer_class'); // e.g., App\Filament\Imports\EmployeeImporter
    $table->json('column_map');       // Saved column mappings
    $table->json('field_fingerprint'); // Hash of CSV headers for auto-matching
    $table->foreignId('user_id')->nullable()->constrained();
    $table->timestamps();
});
```

### 2. Create Model

```php
class ImportTemplate extends Model
{
    protected $casts = [
        'column_map' => 'array',
        'field_fingerprint' => 'array',
    ];

    public function matches(array $csvHeaders): bool
    {
        $normalized = array_map('strtolower', $csvHeaders);
        sort($normalized);
        return $this->field_fingerprint === $normalized;
    }
}
```

### 3. Modify ExcelImportAction

Add template selection to the form schema:

```php
$this->schema(fn (ExcelImportAction $action): array => array_merge([
    Select::make('template_id')
        ->label('Use saved template')
        ->options(fn () => ImportTemplate::where('importer_class', $action->getImporter())
            ->pluck('name', 'id'))
        ->reactive()
        ->afterStateUpdated(function (Set $set, $state) {
            if ($state && $template = ImportTemplate::find($state)) {
                $set('columnMap', $template->column_map);
            }
        }),

    FileUpload::make('file')
        // ... existing config
        ->afterStateUpdated(function (...$args) use ($action): void {
            // Existing auto-match logic
            // + check for matching templates by fingerprint
            $headers = /* get headers */;
            $matchingTemplate = ImportTemplate::where('importer_class', $action->getImporter())
                ->get()
                ->first(fn ($t) => $t->matches($headers));

            if ($matchingTemplate) {
                $set('template_id', $matchingTemplate->id);
                $set('columnMap', $matchingTemplate->column_map);
            }
        }),

    // ... column mapping fieldset

    TextInput::make('save_template_name')
        ->label('Save as template')
        ->placeholder('Enter name to save this mapping')
        ->visible(fn (Get $get) => $get('file') instanceof TemporaryUploadedFile),
], $action->getImporter()::getOptionsFormComponents()));
```

### 4. Save Template After Import

In the action handler:

```php
$this->action(function (ExcelImportAction $action, array $data): void {
    // ... existing import logic

    // Save template if name provided
    if (! empty($data['save_template_name'])) {
        $headers = /* get CSV headers */;
        $normalized = array_map('strtolower', $headers);
        sort($normalized);

        ImportTemplate::create([
            'name' => $data['save_template_name'],
            'importer_class' => $action->getImporter(),
            'column_map' => $data['columnMap'],
            'field_fingerprint' => $normalized,
            'user_id' => auth()->id(),
        ]);
    }

    // ... rest of import logic
});
```

---

## Summary

This import/export system provides:

1. **Multi-format support**: CSV, XLSX, XLS via ExcelImportAction
2. **Column mapping wizard**: Auto-matches columns, allows user adjustment
3. **Queued processing**: Large files processed in background batches
4. **Robust error handling**: Per-row validation with downloadable failure CSV
5. **Round-trip compatibility**: Export headers match import field names
6. **Conditional validation**: Handle different record types with different requirements

Key files to create:
- `app/Filament/Actions/ExcelImportAction.php`
- `app/Filament/Imports/YourModelImporter.php`
- `app/Filament/Exports/YourModelExporter.php`
- `app/Filament/Exports/Components/ExportColumn.php`

Key packages required:
- `maatwebsite/excel` (provides PhpSpreadsheet for Excel parsing)
- `filament/filament` (provides base ImportAction)
