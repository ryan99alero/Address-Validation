# Worker Engine & Batch Processing Guide

This document provides comprehensive instructions for implementing a batch import/export system with worker engine processing, user downloads, and address validation.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Database Schema](#database-schema)
3. [Worker Engine Pattern](#worker-engine-pattern)
4. [Batch Management Page (Tabbed Interface)](#batch-management-page-tabbed-interface)
5. [Named Imports](#named-imports)
6. [Export Batch Selection](#export-batch-selection)
7. [Header Conversion Mapping](#header-conversion-mapping)
8. [Address Validation Filters](#address-validation-filters)
9. [User Menu Downloads](#user-menu-downloads)
10. [Implementation Checklist](#implementation-checklist)

---

## Architecture Overview

### System Flow

```
User uploads batch file
       ↓
BatchImport record created (named import)
       ↓
ProcessBatchImportJob dispatched to queue
       ↓
Worker tracks progress via SystemTask
       ↓
Records processed with header conversion
       ↓
Database notification sent to user
       ↓
User accesses download via profile menu
```

### Key Components

| Component | Purpose |
|-----------|---------|
| `BatchImport` model | Tracks individual import batches with naming |
| `BatchExport` model | Tracks export requests with format conversion |
| `HeaderMapping` model | A=B column conversion between formats |
| `AddressValidation` model | Stores validated addresses with DPV/confidence |
| `ProcessBatchImportJob` | Queue worker for imports |
| `ProcessBatchExportJob` | Queue worker for exports |
| `BatchManagement` page | Filament tabbed interface |
| `TracksSystemTask` trait | Progress tracking for workers |

### File Structure

```
app/
├── Filament/
│   └── Pages/
│       └── BatchManagement.php       # Tabbed page (Import, Export, Addresses)
├── Jobs/
│   ├── ProcessBatchImportJob.php     # Import worker
│   └── ProcessBatchExportJob.php     # Export worker
├── Models/
│   ├── BatchImport.php               # Import batch with naming
│   ├── BatchExport.php               # Export batch with format selection
│   ├── HeaderMapping.php             # Column A=B conversion rules
│   └── AddressValidation.php         # Address validation results
├── Services/
│   ├── HeaderConversionService.php   # Applies A=B mappings
│   ├── AddressValidationService.php  # Address validation with filters
│   └── FormatAwareExportService.php  # Multi-format exports (existing)
└── Traits/
    └── TracksSystemTask.php          # Progress tracking (existing)
```

---

## Database Schema

### 1. batch_imports Table

```php
Schema::create('batch_imports', function (Blueprint $table) {
    $table->id();
    $table->string('name');                          // User-provided or filename
    $table->string('original_filename');             // Original uploaded filename
    $table->string('file_path');                     // Stored file location
    $table->string('original_format', 10);           // csv, xlsx, xls
    $table->string('importer_class');                // Which importer to use
    $table->foreignId('header_mapping_id')->nullable()->constrained();
    $table->foreignId('user_id')->constrained();
    $table->string('status')->default('pending');    // pending, processing, completed, failed
    $table->integer('progress')->default(0);
    $table->string('progress_message')->nullable();
    $table->integer('total_rows')->default(0);
    $table->integer('processed_rows')->default(0);
    $table->integer('successful_rows')->default(0);
    $table->integer('failed_rows')->default(0);
    $table->text('error_message')->nullable();
    $table->json('options')->nullable();             // Importer-specific options
    $table->json('column_map')->nullable();          // Column mappings used
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
    $table->index('created_at');
});
```

### 2. batch_exports Table

```php
Schema::create('batch_exports', function (Blueprint $table) {
    $table->id();
    $table->string('name');                          // Export name
    $table->string('exporter_class');                // Which exporter to use
    $table->string('format', 10)->default('csv');    // Output format: csv, xlsx, xls
    $table->foreignId('source_import_id')->nullable()->constrained('batch_imports');
    $table->foreignId('header_mapping_id')->nullable()->constrained();
    $table->foreignId('user_id')->constrained();
    $table->string('status')->default('pending');
    $table->integer('progress')->default(0);
    $table->string('progress_message')->nullable();
    $table->integer('total_rows')->default(0);
    $table->integer('processed_rows')->default(0);
    $table->string('file_path')->nullable();         // Output file location
    $table->text('error_message')->nullable();
    $table->json('filters')->nullable();             // Applied filters
    $table->json('options')->nullable();             // Export options
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
});
```

### 3. header_mappings Table

```php
Schema::create('header_mappings', function (Blueprint $table) {
    $table->id();
    $table->string('name');                          // Mapping template name
    $table->string('source_format');                 // Source format identifier
    $table->string('target_format');                 // Target format identifier
    $table->json('column_rules');                    // Array of {source: "A", target: "B"}
    $table->json('file_headers')->nullable();        // Headers from original file
    $table->string('headers_hash', 64)->nullable();  // For auto-matching
    $table->foreignId('user_id')->nullable()->constrained();
    $table->boolean('is_system')->default(false);    // System vs user template
    $table->timestamps();

    $table->index(['source_format', 'target_format']);
    $table->index('headers_hash');
});
```

### 4. address_validations Table

```php
Schema::create('address_validations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('batch_import_id')->constrained();
    $table->foreignId('record_id')->nullable();      // Related record if applicable
    $table->string('record_type')->nullable();       // Polymorphic type

    // Input address
    $table->string('input_address1')->nullable();
    $table->string('input_address2')->nullable();
    $table->string('input_city')->nullable();
    $table->string('input_state')->nullable();
    $table->string('input_zip')->nullable();

    // Validated/corrected address
    $table->string('validated_address1')->nullable();
    $table->string('validated_address2')->nullable();
    $table->string('validated_city')->nullable();
    $table->string('validated_state')->nullable();
    $table->string('validated_zip')->nullable();
    $table->string('validated_zip4')->nullable();

    // Validation scores - KEY FIELDS FOR FILTERING
    $table->string('dpv_status', 10)->nullable();    // Y, N, S, D (Delivery Point Valid)
    $table->integer('confidence')->default(0);       // 0-100 confidence score
    $table->string('match_type')->nullable();        // exact, partial, none

    // Additional metadata
    $table->boolean('is_commercial')->default(false);
    $table->boolean('is_vacant')->default(false);
    $table->string('carrier_route')->nullable();
    $table->json('metadata')->nullable();            // Additional validation data

    $table->timestamps();

    $table->index(['batch_import_id', 'dpv_status']);
    $table->index(['batch_import_id', 'confidence']);
    $table->index('record_type');
});
```

---

## Worker Engine Pattern

### TracksSystemTask Trait (Existing)

The system uses `TracksSystemTask` trait for progress tracking:

```php
use App\Traits\TracksSystemTask;

class ProcessBatchImportJob implements ShouldQueue
{
    use Queueable, TracksSystemTask;

    public int $timeout = 600; // 10 minutes
    public int $tries = 1;

    public function __construct(
        protected int $batchImportId,
        protected ?int $userId = null
    ) {}

    public function handle(): void
    {
        $batch = BatchImport::find($this->batchImportId);

        // Initialize system task for tracking
        $this->initializeSystemTask(
            type: SystemTask::TYPE_IMPORT,
            name: "Batch Import: {$batch->name}",
            description: "{$batch->total_rows} rows from {$batch->original_filename}",
            totalRecords: $batch->total_rows,
            relatedModel: BatchImport::class,
            relatedId: $batch->id,
            userId: $this->userId
        );

        try {
            // Step 1: Initialize
            $this->updateProgress($batch, 5, 'Loading file...');
            $this->updateTaskProgress(5, 'Loading file...');

            // Step 2: Parse file
            $this->updateProgress($batch, 10, 'Parsing data...');
            $rows = $this->parseFile($batch);

            // Step 3: Apply header mapping if configured
            if ($batch->header_mapping_id) {
                $this->updateProgress($batch, 15, 'Applying header conversion...');
                $rows = $this->applyHeaderMapping($rows, $batch->headerMapping);
            }

            // Step 4: Process rows
            $processed = 0;
            $successful = 0;
            $failed = 0;
            $total = count($rows);

            foreach ($rows as $row) {
                try {
                    $this->processRow($row, $batch);
                    $successful++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->logFailedRow($batch, $row, $e->getMessage());
                }

                $processed++;

                // Update progress every 50 rows
                if ($processed % 50 === 0 || $processed === $total) {
                    $progress = 20 + (int)(($processed / $total) * 75);
                    $this->updateProgress($batch, $progress, "Processing row {$processed} of {$total}...");
                    $this->updateTaskProgress($progress, "Processing row {$processed} of {$total}...", $processed);
                }
            }

            // Step 5: Complete
            $this->updateProgress($batch, 100, 'Import complete!');
            $batch->markCompleted($successful, $failed);

            $this->updateTaskRecords($processed, $successful, $failed);
            $this->completeTask("Imported {$successful} records, {$failed} failed");

            $this->notifyUser($batch, true);

        } catch (\Exception $e) {
            $batch->markFailed($e->getMessage());
            $this->failTask($e->getMessage());
            $this->notifyUser($batch, false);
        }
    }

    protected function updateProgress(BatchImport $batch, int $progress, string $message): void
    {
        $batch->update([
            'progress' => $progress,
            'progress_message' => $message,
        ]);
    }

    protected function notifyUser(BatchImport $batch, bool $success): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        if ($success) {
            Notification::make()
                ->success()
                ->title('Import Completed')
                ->body("Imported {$batch->successful_rows} records from {$batch->name}")
                ->actions([
                    \Filament\Actions\Action::make('view')
                        ->label('View Results')
                        ->url(route('filament.admin.pages.batch-management', ['tab' => 'import'])),
                ])
                ->sendToDatabase($user);
        } else {
            Notification::make()
                ->danger()
                ->title('Import Failed')
                ->body($batch->error_message ?? 'Unknown error occurred')
                ->sendToDatabase($user);
        }
    }
}
```

### ProcessBatchExportJob

```php
class ProcessBatchExportJob implements ShouldQueue
{
    use Queueable, TracksSystemTask;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        protected int $batchExportId,
        protected ?int $userId = null
    ) {}

    public function handle(
        HeaderConversionService $headerService,
        FormatAwareExportService $exportService
    ): void {
        $export = BatchExport::with(['sourceImport', 'headerMapping'])->find($this->batchExportId);

        $this->initializeSystemTask(
            type: SystemTask::TYPE_EXPORT,
            name: "Batch Export: {$export->name}",
            description: "Format: {$export->format}",
            relatedModel: BatchExport::class,
            relatedId: $export->id,
            userId: $this->userId
        );

        try {
            // Step 1: Load source data
            $this->updateProgress($export, 10, 'Loading source data...');
            $data = $this->loadSourceData($export);

            $export->update(['total_rows' => count($data)]);

            // Step 2: Apply header mapping if converting formats
            if ($export->header_mapping_id) {
                $this->updateProgress($export, 30, 'Converting headers...');
                $data = $headerService->convert($data, $export->headerMapping);
            }

            // Step 3: Apply filters
            if (!empty($export->filters)) {
                $this->updateProgress($export, 50, 'Applying filters...');
                $data = $this->applyFilters($data, $export->filters);
            }

            // Step 4: Generate output file
            $this->updateProgress($export, 70, "Generating {$export->format} file...");
            $filePath = $this->generateExportFile($data, $export, $exportService);

            // Step 5: Complete
            $export->update([
                'status' => 'completed',
                'progress' => 100,
                'progress_message' => 'Export complete!',
                'file_path' => $filePath,
                'processed_rows' => count($data),
                'completed_at' => now(),
            ]);

            $this->updateTaskRecords(count($data), count($data), 0);
            $this->completeTask("Exported {$export->processed_rows} records", $filePath);

            $this->notifyUser($export, true);

        } catch (\Exception $e) {
            $export->markFailed($e->getMessage());
            $this->failTask($e->getMessage());
            $this->notifyUser($export, false);
        }
    }

    protected function generateExportFile(array $data, BatchExport $export, FormatAwareExportService $service): string
    {
        $filename = Str::slug($export->name) . '_' . now()->format('Y-m-d_His');
        $format = $export->format;

        // Write to storage
        $path = "batch_exports/{$filename}.{$format}";

        // Use service for format-aware writing
        // Store to local disk and return path
        return $path;
    }

    protected function notifyUser(BatchExport $export, bool $success): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        if ($success) {
            Notification::make()
                ->success()
                ->title('Export Ready')
                ->body("{$export->name}: {$export->processed_rows} records")
                ->actions([
                    \Filament\Actions\Action::make('download')
                        ->label('Download')
                        ->url(route('batch.export.download', $export->id))
                        ->openUrlInNewTab(),
                ])
                ->sendToDatabase($user);
        } else {
            Notification::make()
                ->danger()
                ->title('Export Failed')
                ->body($export->error_message ?? 'Unknown error occurred')
                ->sendToDatabase($user);
        }
    }
}
```

---

## Batch Management Page (Tabbed Interface)

Based on the VacationManagement pattern, create a tabbed page:

```php
<?php

namespace App\Filament\Pages;

use App\Models\AddressValidation;
use App\Models\BatchExport;
use App\Models\BatchImport;
use App\Models\HeaderMapping;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BatchManagement extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Data Management';
    protected static ?string $navigationLabel = 'Batch Processing';
    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.batch-management';

    public string $activeTab = 'import';

    public function mount(): void
    {
        $this->activeTab = request('tab', 'import');
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return match ($this->activeTab) {
            'export' => $this->getExportTable($table),
            'addresses' => $this->getAddressTable($table),
            'mappings' => $this->getMappingsTable($table),
            default => $this->getImportTable($table),
        };
    }

    // ============================================
    // IMPORT TAB
    // ============================================

    protected function getImportTable(Table $table): Table
    {
        return $table
            ->query(BatchImport::query()->where('user_id', auth()->id()))
            ->columns([
                TextColumn::make('name')
                    ->label('Import Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('original_filename')
                    ->label('Source File')
                    ->limit(30),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->formatStateUsing(fn ($state, $record) =>
                        $record->status === 'processing'
                            ? "{$state}% - {$record->progress_message}"
                            : "{$state}%"
                    ),

                TextColumn::make('total_rows')
                    ->label('Total')
                    ->numeric(),

                TextColumn::make('successful_rows')
                    ->label('Success')
                    ->numeric()
                    ->color('success'),

                TextColumn::make('failed_rows')
                    ->label('Failed')
                    ->numeric()
                    ->color('danger'),

                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->recordActions([
                Action::make('export')
                    ->label('Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (BatchImport $record) => $record->status === 'completed')
                    ->action(fn (BatchImport $record) => $this->createExportFromImport($record)),

                Action::make('download_failures')
                    ->label('Failures')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->visible(fn (BatchImport $record) => $record->failed_rows > 0)
                    ->url(fn (BatchImport $record) => route('batch.import.failures', $record)),
            ])
            ->poll('5s'); // Live refresh while processing
    }

    // ============================================
    // EXPORT TAB
    // ============================================

    protected function getExportTable(Table $table): Table
    {
        return $table
            ->query(BatchExport::query()->where('user_id', auth()->id()))
            ->columns([
                TextColumn::make('name')
                    ->label('Export Name')
                    ->searchable(),

                TextColumn::make('format')
                    ->label('Format')
                    ->badge()
                    ->color('info'),

                TextColumn::make('sourceImport.name')
                    ->label('Source Import')
                    ->placeholder('Direct export'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('processed_rows')
                    ->label('Rows')
                    ->numeric(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (BatchExport $record) => $record->status === 'completed' && $record->file_path)
                    ->url(fn (BatchExport $record) => route('batch.export.download', $record))
                    ->openUrlInNewTab(),
            ])
            ->poll('5s');
    }

    // ============================================
    // ADDRESSES TAB (with validation filters)
    // ============================================

    protected function getAddressTable(Table $table): Table
    {
        return $table
            ->query(
                AddressValidation::query()
                    ->whereHas('batchImport', fn ($q) => $q->where('user_id', auth()->id()))
            )
            ->columns([
                TextColumn::make('batchImport.name')
                    ->label('Import Batch'),

                TextColumn::make('input_address1')
                    ->label('Input Address')
                    ->limit(30),

                TextColumn::make('validated_address1')
                    ->label('Validated Address')
                    ->limit(30),

                TextColumn::make('validated_city')
                    ->label('City'),

                TextColumn::make('validated_state')
                    ->label('State'),

                TextColumn::make('validated_zip')
                    ->label('ZIP'),

                // DPV Status column
                TextColumn::make('dpv_status')
                    ->label('DPV')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Y' => 'success',     // Valid
                        'S' => 'warning',     // Secondary (apt missing)
                        'D' => 'warning',     // Default (no secondary)
                        'N' => 'danger',      // Not valid
                        default => 'gray',
                    })
                    ->tooltip(fn (?string $state): string => match ($state) {
                        'Y' => 'Delivery Point Valid',
                        'S' => 'Secondary Missing (apt/suite)',
                        'D' => 'Default (no secondary)',
                        'N' => 'Not Valid',
                        default => 'Unknown',
                    }),

                // Confidence score column
                TextColumn::make('confidence')
                    ->label('Confidence')
                    ->numeric()
                    ->suffix('%')
                    ->color(fn (int $state): string => match (true) {
                        $state >= 80 => 'success',
                        $state >= 50 => 'warning',
                        default => 'danger',
                    }),

                IconColumn::make('is_commercial')
                    ->label('Commercial')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // DPV Status filter
                SelectFilter::make('dpv_status')
                    ->label('DPV Status')
                    ->options([
                        'Y' => 'Y - Valid',
                        'S' => 'S - Secondary Missing',
                        'D' => 'D - Default',
                        'N' => 'N - Not Valid',
                    ]),

                // Confidence filter with operators: 40+, 40-
                Filter::make('confidence_above')
                    ->label('Confidence 40+')
                    ->query(fn (Builder $query): Builder => $query->where('confidence', '>=', 40))
                    ->toggle(),

                Filter::make('confidence_below')
                    ->label('Confidence 40-')
                    ->query(fn (Builder $query): Builder => $query->where('confidence', '<', 40))
                    ->toggle(),

                // High confidence filter
                Filter::make('high_confidence')
                    ->label('High Confidence (80+)')
                    ->query(fn (Builder $query): Builder => $query->where('confidence', '>=', 80))
                    ->toggle(),

                // Valid only filter
                Filter::make('valid_only')
                    ->label('Valid DPV Only')
                    ->query(fn (Builder $query): Builder => $query->where('dpv_status', 'Y'))
                    ->toggle(),

                // Batch import filter
                SelectFilter::make('batch_import_id')
                    ->label('Import Batch')
                    ->relationship('batchImport', 'name'),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkAction::make('exportSelected')
                    ->label('Export Selected')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($records) {
                        // Export selected addresses
                        return $this->exportAddresses($records);
                    }),
            ]);
    }

    // ============================================
    // MAPPINGS TAB
    // ============================================

    protected function getMappingsTable(Table $table): Table
    {
        return $table
            ->query(
                HeaderMapping::query()
                    ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', auth()->id()))
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Mapping Name')
                    ->searchable(),

                TextColumn::make('source_format')
                    ->label('Source Format')
                    ->badge(),

                TextColumn::make('target_format')
                    ->label('Target Format')
                    ->badge(),

                IconColumn::make('is_system')
                    ->label('System')
                    ->boolean(),

                TextColumn::make('column_rules')
                    ->label('Rules')
                    ->formatStateUsing(fn ($state) => count($state ?? []) . ' rules'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil')
                    ->visible(fn (HeaderMapping $record) => !$record->is_system),

                Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(fn (HeaderMapping $record) => $this->duplicateMapping($record)),
            ]);
    }

    // ============================================
    // HEADER ACTIONS
    // ============================================

    protected function getHeaderActions(): array
    {
        return [
            // New Import action
            Action::make('newImport')
                ->label('New Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->visible(fn () => $this->activeTab === 'import')
                ->form([
                    TextInput::make('name')
                        ->label('Import Name')
                        ->placeholder('Leave blank to use filename')
                        ->maxLength(255),

                    FileUpload::make('file')
                        ->label('File')
                        ->acceptedFileTypes([
                            'text/csv',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->required(),

                    Select::make('importer_class')
                        ->label('Import Type')
                        ->options([
                            'employees' => 'Employees',
                            'addresses' => 'Addresses',
                            'clock_events' => 'Clock Events',
                        ])
                        ->required(),

                    Select::make('header_mapping_id')
                        ->label('Header Mapping (Optional)')
                        ->options(fn () => HeaderMapping::query()
                            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', auth()->id()))
                            ->pluck('name', 'id'))
                        ->placeholder('Auto-detect or none'),
                ])
                ->action(function (array $data) {
                    $this->startImport($data);
                }),

            // New Export action
            Action::make('newExport')
                ->label('New Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn () => $this->activeTab === 'export')
                ->form([
                    TextInput::make('name')
                        ->label('Export Name')
                        ->required()
                        ->maxLength(255),

                    Select::make('source_import_id')
                        ->label('Source Import')
                        ->options(fn () => BatchImport::where('user_id', auth()->id())
                            ->where('status', 'completed')
                            ->pluck('name', 'id'))
                        ->placeholder('Select a completed import')
                        ->required(),

                    Select::make('format')
                        ->label('Output Format')
                        ->options([
                            'csv' => 'CSV',
                            'xlsx' => 'Excel (XLSX)',
                            'xls' => 'Excel Legacy (XLS)',
                        ])
                        ->default('csv')
                        ->required(),

                    Select::make('header_mapping_id')
                        ->label('Header Conversion (Optional)')
                        ->options(fn () => HeaderMapping::query()
                            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', auth()->id()))
                            ->pluck('name', 'id'))
                        ->placeholder('Keep original headers'),
                ])
                ->action(function (array $data) {
                    $this->startExport($data);
                }),

            // New Mapping action
            Action::make('newMapping')
                ->label('New Mapping')
                ->icon('heroicon-o-arrows-right-left')
                ->color('warning')
                ->visible(fn () => $this->activeTab === 'mappings')
                ->url(route('filament.admin.resources.header-mappings.create')),
        ];
    }

    // ============================================
    // ACTION METHODS
    // ============================================

    protected function startImport(array $data): void
    {
        $file = $data['file'];
        $originalFilename = $file->getClientOriginalName();

        $batch = BatchImport::create([
            'name' => $data['name'] ?: pathinfo($originalFilename, PATHINFO_FILENAME),
            'original_filename' => $originalFilename,
            'file_path' => $file->store('batch_imports'),
            'original_format' => strtolower($file->getClientOriginalExtension()),
            'importer_class' => $data['importer_class'],
            'header_mapping_id' => $data['header_mapping_id'] ?? null,
            'user_id' => auth()->id(),
            'status' => 'pending',
        ]);

        // Dispatch job
        ProcessBatchImportJob::dispatch($batch->id, auth()->id());

        Notification::make()
            ->title('Import Started')
            ->body("Processing {$batch->name}...")
            ->success()
            ->send();
    }

    protected function startExport(array $data): void
    {
        $export = BatchExport::create([
            'name' => $data['name'],
            'exporter_class' => 'batch', // Generic batch exporter
            'format' => $data['format'],
            'source_import_id' => $data['source_import_id'],
            'header_mapping_id' => $data['header_mapping_id'] ?? null,
            'user_id' => auth()->id(),
            'status' => 'pending',
        ]);

        ProcessBatchExportJob::dispatch($export->id, auth()->id());

        Notification::make()
            ->title('Export Started')
            ->body("Generating {$export->name}...")
            ->success()
            ->send();
    }

    protected function createExportFromImport(BatchImport $import): void
    {
        // Pre-fill export form with import data
        $this->setActiveTab('export');

        // Could dispatch directly or redirect to form
        Notification::make()
            ->title('Create Export')
            ->body("Create export from '{$import->name}' using the New Export button")
            ->info()
            ->send();
    }
}
```

### Blade Template

Create `resources/views/filament/pages/batch-management.blade.php`:

```blade
<x-filament-panels::page>
    @php
        $processingCount = \App\Models\BatchImport::where('user_id', auth()->id())
            ->where('status', 'processing')
            ->count();
    @endphp

    {{-- Processing Alert (shown when not on import tab) --}}
    @if($processingCount > 0 && $activeTab !== 'import')
        <div style="margin-bottom: 16px;">
            <x-filament::section>
                <div style="display: flex; align-items: center; gap: 12px; background-color: rgba(251, 191, 36, 0.1); padding: 12px 16px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                    <x-heroicon-o-arrow-path style="width: 24px; height: 24px; color: #f59e0b; flex-shrink: 0;" class="animate-spin" />
                    <div>
                        <p style="font-weight: 600; color: #b45309;">{{ $processingCount }} Import(s) Processing</p>
                        <p style="font-size: 0.875rem; color: #92400e;">
                            <button wire:click="setActiveTab('import')" style="font-weight: 600; text-decoration: underline; cursor: pointer; background: none; border: none; color: #92400e;">
                                View Progress
                            </button>
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{-- Tabs Navigation using Filament's native components --}}
    <x-filament::tabs label="Batch Management Tabs">
        <x-filament::tabs.item
            :active="$activeTab === 'import'"
            wire:click="setActiveTab('import')"
            icon="heroicon-o-arrow-up-tray"
        >
            Imports
            @if($processingCount > 0)
                <x-slot name="badge">
                    {{ $processingCount }}
                </x-slot>
            @endif
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'export'"
            wire:click="setActiveTab('export')"
            icon="heroicon-o-arrow-down-tray"
        >
            Exports
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'addresses'"
            wire:click="setActiveTab('addresses')"
            icon="heroicon-o-map-pin"
        >
            Addresses
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'mappings'"
            wire:click="setActiveTab('mappings')"
            icon="heroicon-o-arrows-right-left"
        >
            Mappings
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Table Content --}}
    {{ $this->table }}
</x-filament-panels::page>
```

---

## Named Imports

Imports can be named either by:
1. User-provided name in the import form
2. Automatic fallback to original filename (without extension)

```php
// In BatchImport model
public function getDisplayNameAttribute(): string
{
    return $this->name ?: pathinfo($this->original_filename, PATHINFO_FILENAME);
}

// In import creation
$batch = BatchImport::create([
    'name' => $data['name'] ?: pathinfo($originalFilename, PATHINFO_FILENAME),
    // ...
]);
```

---

## Export Batch Selection

Users can select from completed imports to create exports:

```php
Select::make('source_import_id')
    ->label('Source Import')
    ->options(fn () => BatchImport::where('user_id', auth()->id())
        ->where('status', 'completed')
        ->orderBy('created_at', 'desc')
        ->get()
        ->mapWithKeys(fn ($i) => [
            $i->id => "{$i->name} ({$i->successful_rows} rows) - " . $i->created_at->format('M j, Y')
        ]))
    ->searchable()
    ->required(),
```

---

## Header Conversion Mapping

### HeaderMapping Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeaderMapping extends Model
{
    protected $fillable = [
        'name',
        'source_format',
        'target_format',
        'column_rules',
        'file_headers',
        'headers_hash',
        'user_id',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'column_rules' => 'array',
            'file_headers' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convert a single header using mapping rules.
     *
     * @param string $sourceHeader Original header name
     * @return string Converted header name (or original if no rule)
     */
    public function convertHeader(string $sourceHeader): string
    {
        foreach ($this->column_rules ?? [] as $rule) {
            if (strcasecmp($rule['source'], $sourceHeader) === 0) {
                return $rule['target'];
            }
        }

        return $sourceHeader;
    }

    /**
     * Convert all headers in an array.
     */
    public function convertHeaders(array $headers): array
    {
        return array_map(fn ($h) => $this->convertHeader($h), $headers);
    }

    /**
     * Generate hash for auto-matching.
     */
    public static function generateHeadersHash(array $headers): string
    {
        $normalized = array_map(fn ($h) => strtolower(trim($h)), $headers);
        sort($normalized);
        return hash('sha256', implode('|', $normalized));
    }

    /**
     * Find matching mapping by headers.
     */
    public static function findByHeaders(array $headers, ?int $userId = null): ?self
    {
        $hash = self::generateHeadersHash($headers);

        // User's mapping first
        if ($userId) {
            $mapping = self::where('headers_hash', $hash)
                ->where('user_id', $userId)
                ->first();

            if ($mapping) {
                return $mapping;
            }
        }

        // System mapping fallback
        return self::where('headers_hash', $hash)
            ->where('is_system', true)
            ->first();
    }
}
```

### HeaderConversionService

```php
<?php

namespace App\Services;

use App\Models\HeaderMapping;

class HeaderConversionService
{
    /**
     * Convert data rows using header mapping.
     *
     * @param array $rows Array of associative arrays
     * @param HeaderMapping $mapping Mapping rules
     * @return array Converted rows with new headers
     */
    public function convert(array $rows, HeaderMapping $mapping): array
    {
        if (empty($rows)) {
            return $rows;
        }

        return array_map(function ($row) use ($mapping) {
            $converted = [];

            foreach ($row as $header => $value) {
                $newHeader = $mapping->convertHeader($header);
                $converted[$newHeader] = $value;
            }

            return $converted;
        }, $rows);
    }

    /**
     * Convert headers only (for preview).
     */
    public function convertHeadersOnly(array $headers, HeaderMapping $mapping): array
    {
        return $mapping->convertHeaders($headers);
    }

    /**
     * Get diff between original and converted headers.
     */
    public function getHeaderDiff(array $originalHeaders, HeaderMapping $mapping): array
    {
        $diff = [];

        foreach ($originalHeaders as $original) {
            $converted = $mapping->convertHeader($original);
            if ($original !== $converted) {
                $diff[] = [
                    'original' => $original,
                    'converted' => $converted,
                ];
            }
        }

        return $diff;
    }
}
```

### Mapping Rule Format

The `column_rules` JSON structure:

```json
[
    {"source": "First Name", "target": "first_name"},
    {"source": "Last Name", "target": "last_name"},
    {"source": "Street Address", "target": "address1"},
    {"source": "Apartment", "target": "address2"},
    {"source": "City Name", "target": "city"},
    {"source": "State/Province", "target": "state"},
    {"source": "Postal Code", "target": "zip"}
]
```

### Integration with ImportTemplate

Header mappings can reuse the same auto-matching logic as ImportTemplate:

```php
// When importing, check for matching mapping
$headers = $this->getCsvHeaders($file);
$mapping = HeaderMapping::findByHeaders($headers, auth()->id());

if ($mapping) {
    // Auto-apply mapping
    $set('header_mapping_id', $mapping->id);

    Notification::make()
        ->title('Mapping Auto-Applied')
        ->body("Using '{$mapping->name}' header conversion")
        ->success()
        ->send();
}
```

---

## Address Validation Filters

### Filter Operators

The address table supports comparison operators for numeric fields:

| Operator | Example | Description |
|----------|---------|-------------|
| `40+` | Confidence >= 40 | Greater than or equal |
| `40-` | Confidence < 40 | Less than |
| `80+` | Confidence >= 80 | High confidence |

### Filter Implementation

```php
// In getAddressTable()
->filters([
    // DPV Status (exact match)
    SelectFilter::make('dpv_status')
        ->label('DPV Status')
        ->options([
            'Y' => 'Y - Valid',
            'S' => 'S - Secondary Missing',
            'D' => 'D - Default',
            'N' => 'N - Not Valid',
        ]),

    // Confidence 40+ (greater than or equal)
    Filter::make('confidence_gte_40')
        ->label('Confidence 40+')
        ->query(fn (Builder $query): Builder => $query->where('confidence', '>=', 40))
        ->toggle(),

    // Confidence 40- (less than)
    Filter::make('confidence_lt_40')
        ->label('Confidence 40-')
        ->query(fn (Builder $query): Builder => $query->where('confidence', '<', 40))
        ->toggle(),

    // High confidence (80+)
    Filter::make('confidence_gte_80')
        ->label('High Confidence (80+)')
        ->query(fn (Builder $query): Builder => $query->where('confidence', '>=', 80))
        ->toggle(),

    // Custom numeric filter with TextInput
    Filter::make('custom_confidence')
        ->form([
            TextInput::make('min_confidence')
                ->label('Min Confidence')
                ->numeric()
                ->minValue(0)
                ->maxValue(100),
            TextInput::make('max_confidence')
                ->label('Max Confidence')
                ->numeric()
                ->minValue(0)
                ->maxValue(100),
        ])
        ->query(function (Builder $query, array $data): Builder {
            return $query
                ->when(
                    $data['min_confidence'],
                    fn (Builder $q, $min) => $q->where('confidence', '>=', $min)
                )
                ->when(
                    $data['max_confidence'],
                    fn (Builder $q, $max) => $q->where('confidence', '<=', $max)
                );
        }),
])
```

### DPV Status Values

| Value | Meaning | Color |
|-------|---------|-------|
| `Y` | Delivery Point Valid | Success (green) |
| `S` | Secondary (apt/suite) Missing | Warning (yellow) |
| `D` | Default - no secondary needed | Warning (yellow) |
| `N` | Not Valid | Danger (red) |

---

## User Menu Downloads

### Profile Menu Integration

Add download notifications to user profile dropdown.

In `AdminPanelProvider.php`:

```php
->userMenuItems([
    MenuItem::make()
        ->label('Downloads')
        ->icon('heroicon-o-arrow-down-tray')
        ->url(fn () => route('filament.admin.pages.batch-management', ['tab' => 'export']))
        ->badge(fn () => auth()->user()
            ?->unreadNotifications()
            ->whereJsonContains('data->type', 'download')
            ->count() ?: null,
            'success'
        ),
])
```

### Database Notifications with Download Actions

```php
// In job completion
Notification::make()
    ->success()
    ->title('Export Ready')
    ->body("{$export->name}: {$export->processed_rows} records")
    ->actions([
        \Filament\Actions\Action::make('download')
            ->label('Download')
            ->url(route('batch.export.download', $export->id))
            ->openUrlInNewTab()
            ->markAsRead(),
    ])
    ->sendToDatabase($user);
```

### Download Routes

```php
// routes/web.php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/batch/import/{import}/download', [BatchDownloadController::class, 'importFile'])
        ->name('batch.import.download');

    Route::get('/batch/import/{import}/failures', [BatchDownloadController::class, 'importFailures'])
        ->name('batch.import.failures');

    Route::get('/batch/export/{export}/download', [BatchDownloadController::class, 'exportFile'])
        ->name('batch.export.download');
});
```

### BatchDownloadController

```php
<?php

namespace App\Http\Controllers;

use App\Models\BatchExport;
use App\Models\BatchImport;
use App\Services\FormatAwareExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchDownloadController extends Controller
{
    public function __construct(
        protected FormatAwareExportService $exportService
    ) {}

    public function importFile(Request $request, BatchImport $import): StreamedResponse
    {
        $this->authorize('view', $import);

        return Storage::download(
            $import->file_path,
            $import->original_filename
        );
    }

    public function importFailures(Request $request, BatchImport $import): StreamedResponse
    {
        $this->authorize('view', $import);

        $rows = $this->getFailedRows($import);
        $filename = "failures_{$import->name}";

        return $this->exportService->export(
            $rows,
            $filename,
            $import->original_format
        );
    }

    public function exportFile(Request $request, BatchExport $export): StreamedResponse
    {
        $this->authorize('view', $export);

        return Storage::download(
            $export->file_path,
            "{$export->name}.{$export->format}"
        );
    }

    protected function getFailedRows(BatchImport $import): array
    {
        // Implementation to retrieve failed rows
        // Similar to DownloadImportFailureController
    }
}
```

---

## Implementation Checklist

### Phase 1: Database & Models
- [ ] Create `batch_imports` migration
- [ ] Create `batch_exports` migration
- [ ] Create `header_mappings` migration
- [ ] Create `address_validations` migration
- [ ] Create `BatchImport` model
- [ ] Create `BatchExport` model
- [ ] Create `HeaderMapping` model
- [ ] Create `AddressValidation` model

### Phase 2: Services
- [ ] Create `HeaderConversionService`
- [ ] Create `AddressValidationService` (if not exists)
- [ ] Verify `FormatAwareExportService` exists

### Phase 3: Jobs
- [ ] Create `ProcessBatchImportJob`
- [ ] Create `ProcessBatchExportJob`
- [ ] Add notification actions for downloads

### Phase 4: Controllers & Routes
- [ ] Create `BatchDownloadController`
- [ ] Add download routes to `web.php`

### Phase 5: Filament UI
- [ ] Create `BatchManagement` page
- [ ] Create blade template for tabs
- [ ] Create `HeaderMappingResource` for CRUD
- [ ] Add user menu item for downloads

### Phase 6: Testing
- [ ] Test import job with named imports
- [ ] Test export job with format conversion
- [ ] Test header mapping conversion
- [ ] Test address validation filters
- [ ] Test download notifications

---

## Summary

This Worker Engine system provides:

1. **Named Imports**: User-provided names or automatic filename extraction
2. **Batch Tracking**: Progress, status, and record counts
3. **Format-Aware Exports**: CSV, XLSX, XLS output matching input
4. **Header Conversion**: A=B column mapping between formats
5. **Address Validation**: DPV status and confidence filtering with operators
6. **User Downloads**: Profile menu integration with notification badges
7. **Tabbed Management**: Single page with Import, Export, Addresses, Mappings tabs
8. **Queue Processing**: Background jobs with progress tracking
9. **Database Notifications**: Action buttons for direct downloads

Key files to create:
- `app/Filament/Pages/BatchManagement.php` - Main tabbed interface
- `app/Jobs/ProcessBatchImportJob.php` - Import worker
- `app/Jobs/ProcessBatchExportJob.php` - Export worker
- `app/Services/HeaderConversionService.php` - A=B mapping
- `app/Http/Controllers/BatchDownloadController.php` - Download handling
- `app/Models/BatchImport.php`, `BatchExport.php`, `HeaderMapping.php`, `AddressValidation.php`

Key existing files to use:
- `app/Traits/TracksSystemTask.php` - Progress tracking
- `app/Models/SystemTask.php` - Task tracking model
- `app/Services/FormatAwareExportService.php` - Multi-format export
- `app/Filament/Pages/VacationManagement.php` - Tabbed page pattern reference
