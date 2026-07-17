<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessExportBatch;
use App\Jobs\ProcessImportBatchImport;
use App\Jobs\ProcessImportBatchValidation;
use App\Models\Carrier;
use App\Models\CompanySetting;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use App\Models\ImportFieldTemplate;
use App\Models\Plant;
use App\Services\ImportService;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BatchProcessing extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Address Intelligence';

    protected static ?string $navigationLabel = 'Batch Processing';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.batch-processing';

    // Active tab
    public string $activeTab = 'import';

    // ===== IMPORT PROPERTIES =====
    public int $importStep = 1;

    public ?array $uploadData = [];

    public array $headers = [];

    public array $mappings = [];

    public array $previewRows = [];

    public ?string $selectedTemplateId = null;

    public ?string $newTemplateName = null;

    public ?ImportBatch $batch = null;

    public ?string $uploadedFilePath = null;

    public ?string $lastProcessedFile = null;

    public ?string $originalFilename = null;

    public ?string $shipViaCodeColumn = null;

    // ===== EXPORT PROPERTIES =====
    public ?array $exportData = [];

    public ?ImportBatch $selectedExportBatch = null;

    public int $totalAddresses = 0;

    public int $validatedAddresses = 0;

    public int $validAddresses = 0;

    public int $invalidAddresses = 0;

    public function mount(): void
    {
        $this->uploadForm->fill([
            'validation_engine' => Carrier::where('slug', 'fedex')->where('is_active', true)->exists() ? 'fedex' : 'ups',
            'auto_validate' => true,
            'check_both_sources' => true,
        ]);
        $this->exportForm->fill([]);

        // Check if a specific batch was requested via query parameter
        $requestedBatchId = request()->query('batch');
        if ($requestedBatchId) {
            $requestedBatch = ImportBatch::query()
                ->where('id', $requestedBatchId)
                ->where('imported_by', auth()->id())
                ->first();

            if ($requestedBatch) {
                $this->batch = $requestedBatch;
                $this->importStep = 3;

                return;
            }
        }

        // Check for active batch that's still being processed
        $activeBatch = ImportBatch::query()
            ->where('imported_by', auth()->id())
            ->where(function ($query) {
                // Processing status OR completed but validation still in progress
                $query->where('status', ImportBatch::STATUS_PROCESSING)
                    ->orWhere(function ($q) {
                        $q->where('status', ImportBatch::STATUS_COMPLETED)
                            ->whereColumn('validated_rows', '<', 'successful_rows');
                    });
            })
            ->latest()
            ->first();

        if ($activeBatch) {
            $this->batch = $activeBatch;
            $this->importStep = 3;
        }
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /**
     * Active single-carrier validation engines. FedEx↔UPS fallback chains were
     * evaluated and dropped: both carriers validate US addresses against the same
     * USPS/CASS data, so a second carrier recovers ~no additional corrections
     * (measured n=120). validateBatchWithEngine() still supports chains if ever
     * re-enabled, but they are intentionally not offered here.
     *
     * @return array<string, string>
     */
    public function validationEngineOptions(): array
    {
        $active = Carrier::where('is_active', true)->pluck('name', 'slug');
        $options = [];
        if ($active->has('fedex')) {
            $options['fedex'] = 'FedEx';
        }
        if ($active->has('ups')) {
            $options['ups'] = 'UPS';
        }

        return $options;
    }

    /**
     * In-page how-it-works guide for the Batch Processing tabs. Structure matches
     * the flexible guide-panel partial (heading/intro/rule/sections/note).
     *
     * @return array<string, mixed>
     */
    public function viewGuide(): array
    {
        return [
            'heading' => 'How Batch Processing works',
            'description' => 'Validation engines, transit times, BestWay, reverse scheduling — and where the results land',
            'intro' => 'Import a spreadsheet, map its columns, and validate every address. Optionally fetch transit times, optimize the service (BestWay), and reverse-schedule ship dates. Then download the results from the Export tab.',
            'rule' => [
                'label' => 'To see transit / BestWay / ship-date results in your download',
                'text' => 'those columns are only appended when you ask for them. If the batch was run with Time in Transit or BestWay, the Export tab auto-ticks "Include service / transit results" — otherwise tick it yourself. A plain export contains only your original columns.',
            ],
            'sections' => [
                [
                    'title' => 'Validation engine (Import tab)',
                    'items' => [
                        ['name' => 'FedEx / UPS', 'means' => 'Checks the local invoice-correction cache first, then the chosen carrier\'s API for anything not found. FedEx and UPS validate US addresses against the same USPS/CASS data, so they return near-identical results — pick either.', 'how' => 'cache → carrier'],
                        ['name' => 'Check both sources', 'means' => 'Validates against the invoice cache AND the carrier, flagging disagreements as Needs Review for a manual pick.'],
                    ],
                ],
                [
                    'title' => 'Transit & service optimization',
                    'items' => [
                        ['name' => 'Time in Transit', 'means' => 'Fetches each service\'s delivery date, transit days and distance for every address, from your origin ZIP.'],
                        ['name' => 'BestWay', 'means' => 'Re-maps each address\'s Ship Via Code to the cheapest service that still meets the Required On-Site Date (preserving the original code so you can see what changed).'],
                        ['name' => 'Reverse scheduling', 'means' => 'For addresses with a Required On-Site Date, works backward to the latest ship date + cheapest service that still arrives on time. Duration-based and holiday-naive — a planning aid, not a booking guarantee.', 'how' => 'ship date = required date − transit business days'],
                    ],
                ],
                [
                    'title' => 'Export tab',
                    'items' => [
                        ['name' => 'Base format', 'means' => 'Re-use your import field mapping, or a saved Export Template (ePace / WorldShip / FedEx / custom).'],
                        ['name' => 'Include service / transit results', 'means' => 'Appends the validation, transit, BestWay and reverse-schedule columns (including a "What Changed" summary) after your normal columns. Auto-enabled when the batch computed them.'],
                        ['name' => 'Filters', 'means' => 'Limit the export by validation status (valid / invalid / …) and by source (invoice cache vs carrier API).'],
                    ],
                ],
            ],
            'note' => 'Results are computed during import (background job); the Export tab reads what was already calculated.',
        ];
    }

    // ===== IMPORT FORM =====
    public function uploadForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Upload File')
                    ->description('Upload an Excel or CSV file containing addresses to validate.')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Address File')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->required()
                            ->maxSize(10240)
                            ->disk('local')
                            ->directory('imports')
                            ->storeFileNamesIn('original_file_name')
                            ->helperText('Accepted formats: .xlsx, .xls, .csv (max 10MB)'),
                        TextInput::make('import_name')
                            ->label('Import Name')
                            ->placeholder('Leave blank to use filename')
                            ->helperText('Optional: Give this import a custom name for easy identification'),
                        Select::make('validation_engine')
                            ->label('Validation Engine')
                            ->options(fn (): array => $this->validationEngineOptions())
                            ->default('fedex')
                            ->required()
                            ->live()
                            ->helperText('Which carrier API to validate against. Checks the local invoice cache first, then that carrier.'),
                        Checkbox::make('auto_validate')
                            ->label('Automatically start validation after import')
                            ->default(true)
                            ->helperText('If checked, address validation will begin immediately after import'),
                        Checkbox::make('check_both_sources')
                            ->label('Check both sources (Invoice DB + Carrier API)')
                            ->default(true)
                            ->helperText('Validate against both; addresses where they disagree are flagged Needs Review for a manual pick.'),
                        Checkbox::make('include_transit_times')
                            ->label('Include Time in Transit')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $company = CompanySetting::instance();
                                    if ($company->postal_code) {
                                        $set('origin_postal_code', $company->postal_code);
                                    }
                                    // Default to FedEx for transit times if not set
                                    $fedex = Carrier::where('slug', 'fedex')->where('is_active', true)->first();
                                    if ($fedex) {
                                        $set('transit_carrier_id', $fedex->id);
                                    }
                                } else {
                                    // Clear BestWay if transit times disabled
                                    $set('find_best_service', false);
                                }
                            })
                            ->helperText('Fetch shipping service options and delivery estimates'),
                        Select::make('transit_carrier_id')
                            ->label('Transit Time Carrier')
                            ->options(fn () => Carrier::whereIn('slug', ['ups', 'fedex'])
                                ->where('is_active', true)
                                ->pluck('name', 'id'))
                            ->visible(fn ($get) => $get('include_transit_times'))
                            ->required(fn ($get) => $get('include_transit_times'))
                            ->helperText('Select UPS or FedEx for transit time lookups'),
                        TextInput::make('origin_postal_code')
                            ->label('Origin ZIP Code')
                            ->placeholder('e.g., 38017')
                            ->maxLength(10)
                            ->visible(fn ($get) => $get('include_transit_times'))
                            ->required(fn ($get) => $get('include_transit_times'))
                            ->helperText(fn () => CompanySetting::instance()->hasAddress()
                                ? 'Default from Company Setup: '.CompanySetting::instance()->formatted_address
                                : 'Configure default in Settings > Company Setup'),
                        Checkbox::make('find_best_service')
                            ->label('Find Best Service (BestWay Optimization)')
                            ->default(false)
                            ->live()
                            ->visible(fn ($get) => $get('include_transit_times'))
                            ->helperText('Automatically select the most economical shipping service that meets the Required On-Site Date. Original ShipVia will be preserved in Previous_ShipViaCode.'),
                        Select::make('bestway_plant_id')
                            ->label('Plant')
                            ->options(fn (): array => Plant::options())
                            ->native(false)
                            ->visible(fn ($get) => $get('include_transit_times') && $get('find_best_service'))
                            ->helperText("Which plant ships this batch — BestWay resolves the chosen service to this plant's ShipVia code. Leave blank to use the plant on each row's original Ship Via."),
                        DatePicker::make('default_ship_date')
                            ->label('Ship Date (applies to all orders)')
                            ->native(true) // native browser date input — emits plain Y-m-d, no timezone shift
                            ->visible(fn ($get) => $get('include_transit_times') && $get('find_best_service'))
                            ->helperText('When the batch ships — the transit clock starts here. A Ship Date column in the file overrides this per row. Defaults to today if left blank.'),
                        DatePicker::make('default_on_site_date')
                            ->label('On-Site Date (applies to all orders)')
                            ->native(true)
                            ->visible(fn ($get) => $get('include_transit_times') && $get('find_best_service'))
                            ->helperText('Deliver-by deadline. A Required On-Site Date column in the file overrides this per row.'),
                        Checkbox::make('reverse_validate_future_date')
                            ->label('Reverse-Validate Future Ship Date (extra API call)')
                            ->default(false)
                            ->visible(fn ($get) => $get('include_transit_times') && $get('find_best_service'))
                            ->helperText('For future-dated shipments, re-quote the carrier at the chosen ship date to confirm the real (holiday-aware) delivery date instead of the inferred one. Flags any that would arrive late. Costs one extra API call per future-dated shipment.'),
                    ]),
            ])
            ->statePath('uploadData');
    }

    // ===== EXPORT FORM =====
    public function exportForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Export Configuration')
                    ->description('Select a batch to download validated addresses.')
                    ->schema([
                        Select::make('batch_id')
                            ->label('Import Batch')
                            ->options(function () {
                                return ImportBatch::query()
                                    ->where('status', ImportBatch::STATUS_COMPLETED)
                                    ->orderByDesc('created_at')
                                    ->get()
                                    ->mapWithKeys(function (ImportBatch $batch) {
                                        $addressCount = $batch->addresses()->count();
                                        $validatedCount = $batch->addresses()->whereNotNull('validation_status')->count();

                                        return [
                                            $batch->id => "{$batch->display_name} ({$validatedCount}/{$addressCount} validated)",
                                        ];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                $this->updateExportBatchStats($state);
                                $batch = $state ? ImportBatch::find($state) : null;
                                $set('append_service_results', (bool) ($batch?->include_transit_times || $batch?->find_best_service));
                            })
                            ->helperText('Select a completed import batch to export'),

                        Select::make('template_id')
                            ->label('Export Template')
                            ->options(function () {
                                $options = [
                                    '_import_mapping' => '📋 Use Import Field Mapping (Same as Import)',
                                    '_import_with_validation' => '📋 Use Original Import + Add Validation Fields',
                                ];

                                $templates = ExportTemplate::query()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function (ExportTemplate $template) {
                                        $system = ExportTemplate::getTargetSystems()[$template->target_system] ?? $template->target_system;

                                        return [
                                            $template->id => "{$template->name} ({$system})",
                                        ];
                                    })
                                    ->toArray();

                                return $options + $templates;
                            })
                            ->searchable()
                            ->default('_import_mapping')
                            ->helperText('Use import mapping for same format, or add validation fields to original'),

                        Checkbox::make('append_service_results')
                            ->label('Include service / transit results')
                            ->helperText('Appends the transit-time and BestWay result columns (service, delivery date, meets-deadline, distance, what changed…) after your normal columns. Auto-enabled when the batch was run with transit times or BestWay.')
                            ->default(false),

                        Select::make('filter_status')
                            ->label('Filter by Validation Status')
                            ->options([
                                'all' => 'All Addresses',
                                'validated' => 'Validated Only',
                                'valid' => 'Valid Only',
                                'invalid' => 'Invalid Only',
                                'ambiguous' => 'Ambiguous Only',
                            ])
                            ->default('all')
                            ->helperText('Filter which addresses to include in the export'),

                        Select::make('filter_source')
                            ->label('Filter by Validation Source')
                            ->options([
                                'all' => 'All Sources',
                                'local_cache' => 'Invoice DB (Local) Only',
                                'api' => 'Carrier API Only',
                            ])
                            ->default('all')
                            ->helperText('Export only addresses resolved from the local invoice cache, or only those from a carrier API'),

                        Select::make('sort_by')
                            ->label('Sort By')
                            ->options(ExportTemplate::getSortOptions())
                            ->default('original')
                            ->helperText('Choose how to sort the exported addresses'),

                        TextInput::make('filename')
                            ->label('Custom Filename')
                            ->placeholder('Leave blank for auto-generated name')
                            ->helperText('Optional: Specify a custom filename (without extension)'),
                    ]),
            ])
            ->statePath('exportData');
    }

    // ===== IMPORT METHODS =====
    public function processUpload(): void
    {
        $data = $this->uploadForm->getState();

        $filePath = $data['file'];

        if (is_array($filePath)) {
            $filePath = $filePath[0];
        }

        if (empty($filePath) || ! is_string($filePath)) {
            Notification::make()
                ->title('Error')
                ->body('Please upload a valid file.')
                ->danger()
                ->send();

            return;
        }

        try {
            // Increase memory limit for large files
            ini_set('memory_limit', '512M');

            $importService = app(ImportService::class);

            $this->uploadedFilePath = $filePath;
            $storedFilePath = Storage::disk('local')->path($filePath);

            // Get the original filename from the upload (captured by storeFileNamesIn)
            $this->originalFilename = $data['original_file_name'] ?? basename($filePath);

            $file = new UploadedFile($storedFilePath, $this->originalFilename);

            $this->headers = $importService->parseHeaders($file);

            if (empty($this->headers)) {
                Storage::disk('local')->delete($filePath);

                Notification::make()
                    ->title('Error')
                    ->body('Could not read headers from the file.')
                    ->danger()
                    ->send();

                return;
            }

            $this->mappings = $importService->autoMatchHeaders($this->headers);
            $this->previewRows = $importService->getPreviewRows($file, 3);

            // Count rows efficiently without loading all data into memory
            $totalRows = $importService->countRows($file);

            // The engine may be a single carrier or a fallback chain; carrier_id stores
            // the PRIMARY carrier (first in the chain) so chunk-sizing, transit and other
            // Carrier-model lookups keep working. validation_engine drives the loop.
            $engine = $data['validation_engine'] ?? 'fedex';
            $primarySlug = explode('_', $engine)[0];
            $primaryCarrier = Carrier::where('slug', $primarySlug)->where('is_active', true)->first();

            $importName = $data['import_name'] ?? null;
            $this->batch = ImportBatch::create([
                'name' => $importName ?: pathinfo($this->originalFilename, PATHINFO_FILENAME),
                'original_filename' => $this->originalFilename,
                'file_path' => $filePath,
                'status' => ImportBatch::STATUS_MAPPING,
                'total_rows' => $totalRows,
                'carrier_id' => $primaryCarrier?->id,
                'validation_engine' => $engine,
                'include_transit_times' => $data['include_transit_times'] ?? false,
                'check_both_sources' => $data['check_both_sources'] ?? false,
                'transit_carrier_id' => $data['transit_carrier_id'] ?? null,
                'origin_postal_code' => $data['origin_postal_code'] ?? null,
                'origin_country_code' => 'US',
                'find_best_service' => $data['find_best_service'] ?? false,
                'reverse_validate_future_date' => $data['reverse_validate_future_date'] ?? false,
                'default_on_site_date' => $data['default_on_site_date'] ?? null,
                'default_ship_date' => $data['default_ship_date'] ?? null,
                'bestway_plant_id' => $data['bestway_plant_id'] ?? null,
                'imported_by' => auth()->id(),
            ]);

            $this->importStep = 2;

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error Processing File')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function loadTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }

        $template = ImportFieldTemplate::find($this->selectedTemplateId);

        if (! $template) {
            return;
        }

        $templateMappings = $template->field_mappings ?? [];

        foreach ($this->mappings as &$mapping) {
            $sourceHeader = $mapping['source'];

            foreach ($templateMappings as $tmpl) {
                if (($tmpl['source'] ?? '') === $sourceHeader) {
                    $mapping['target'] = $tmpl['target'] ?? '';
                    break;
                }
            }
        }

        // Load ship via code field if saved in template
        if ($template->ship_via_code_field) {
            $this->shipViaCodeColumn = $template->ship_via_code_field;
        }

        Notification::make()
            ->title('Template Loaded')
            ->body("Applied mappings from '{$template->name}'")
            ->success()
            ->send();
    }

    public function saveAsTemplate(): void
    {
        if (empty($this->newTemplateName)) {
            Notification::make()
                ->title('Error')
                ->body('Please enter a template name.')
                ->danger()
                ->send();

            return;
        }

        $importService = app(ImportService::class);
        $template = $importService->saveMappingTemplate(
            $this->newTemplateName,
            $this->mappings,
            'Created from batch import on '.now()->format('Y-m-d H:i'),
            $this->shipViaCodeColumn
        );

        $this->selectedTemplateId = (string) $template->id;
        $this->newTemplateName = null;

        Notification::make()
            ->title('Template Saved')
            ->body("Template '{$template->name}' has been saved.")
            ->success()
            ->send();
    }

    public function updateTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            Notification::make()
                ->title('Error')
                ->body('No template selected to update.')
                ->danger()
                ->send();

            return;
        }

        $template = ImportFieldTemplate::find($this->selectedTemplateId);

        if (! $template) {
            Notification::make()
                ->title('Error')
                ->body('Template not found.')
                ->danger()
                ->send();

            return;
        }

        $importService = app(ImportService::class);
        $importService->updateMappingTemplate(
            $template,
            $this->mappings,
            $this->shipViaCodeColumn,
            'Updated from batch import on '.now()->format('Y-m-d H:i')
        );

        Notification::make()
            ->title('Template Updated')
            ->body("Template '{$template->name}' has been updated.")
            ->success()
            ->send();
    }

    public function canUpdateTemplate(): bool
    {
        return ! empty($this->selectedTemplateId) && empty($this->newTemplateName);
    }

    public function startProcessing(): void
    {
        $hasAddress = false;
        foreach ($this->mappings as $mapping) {
            // Check for both old and new field names for backward compatibility
            if (in_array($mapping['target'], ['address_line_1', 'input_address_1'])) {
                $hasAddress = true;
                break;
            }
        }

        if (! $hasAddress) {
            Notification::make()
                ->title('Validation Error')
                ->body('You must map at least Address Line 1 to proceed.')
                ->danger()
                ->send();

            return;
        }

        if ($this->selectedTemplateId) {
            $this->batch->update([
                'mapping_template_id' => $this->selectedTemplateId,
            ]);
        }

        $this->batch->update([
            'field_mappings' => $this->mappings,
            'ship_via_code_column' => $this->shipViaCodeColumn,
            'status' => ImportBatch::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        $this->importStep = 3;

        // Dispatch background job for memory-efficient chunked processing
        $autoValidate = $this->uploadData['auto_validate'] ?? true;

        Log::info('BatchProcessing: Dispatching import job', [
            'batch_id' => $this->batch->id,
            'total_rows' => $this->batch->total_rows,
            'auto_validate' => $autoValidate,
        ]);

        ProcessImportBatchImport::dispatch(
            $this->batch,
            $this->mappings,
            $autoValidate
        );

        Notification::make()
            ->title('Import Started')
            ->body("Processing {$this->batch->total_rows} rows in the background. This page will update automatically.")
            ->success()
            ->send();
    }

    public function refreshBatchProgress(): void
    {
        if ($this->batch) {
            $this->batch->refresh();
        }
    }

    public function cancelValidation(): void
    {
        if ($this->batch && ! $this->batch->isCancelled()) {
            $this->batch->markCancelled();

            Notification::make()
                ->title('Validation Cancelled')
                ->body('The validation process has been cancelled. Already validated addresses will be preserved.')
                ->warning()
                ->send();
        }
    }

    public function resumeValidation(): void
    {
        if (! $this->batch) {
            return;
        }

        // Reset status to allow processing
        $this->batch->update(['status' => ImportBatch::STATUS_COMPLETED]);

        // Dispatch a new validation job
        ProcessImportBatchValidation::dispatch($this->batch);

        Notification::make()
            ->title('Validation Resumed')
            ->body('Validation will continue for remaining addresses.')
            ->success()
            ->send();
    }

    public function startNewImport(): void
    {
        $this->importStep = 1;
        $this->headers = [];
        $this->mappings = [];
        $this->previewRows = [];
        $this->batch = null;
        $this->uploadedFilePath = null;
        $this->selectedTemplateId = null;
        $this->newTemplateName = null;
        $this->lastProcessedFile = null;

        $this->uploadForm->fill([
            'validation_engine' => Carrier::where('slug', 'fedex')->where('is_active', true)->exists() ? 'fedex' : 'ups',
            'auto_validate' => true,
            'check_both_sources' => true,
        ]);
    }

    public function getSystemFields(): array
    {
        return app(ImportService::class)->getSystemFields();
    }

    public function getAvailableTemplates(): array
    {
        return ImportFieldTemplate::orderBy('name')->pluck('name', 'id')->toArray();
    }

    // ===== EXPORT METHODS =====
    public function updateExportBatchStats(?string $batchId): void
    {
        if (! $batchId) {
            $this->selectedExportBatch = null;
            $this->totalAddresses = 0;
            $this->validatedAddresses = 0;
            $this->validAddresses = 0;
            $this->invalidAddresses = 0;

            return;
        }

        $this->selectedExportBatch = ImportBatch::find($batchId);

        if (! $this->selectedExportBatch) {
            return;
        }

        $this->totalAddresses = $this->selectedExportBatch->addresses()->count();
        $this->validatedAddresses = $this->selectedExportBatch->addresses()->whereNotNull('validation_status')->count();
        $this->validAddresses = $this->selectedExportBatch->addresses()
            ->where('validation_status', 'valid')
            ->count();
        $this->invalidAddresses = $this->selectedExportBatch->addresses()
            ->where('validation_status', 'invalid')
            ->count();
    }

    public function startExport(): void
    {
        $data = $this->exportForm->getState();

        if (empty($data['batch_id'])) {
            Notification::make()
                ->title('Error')
                ->body('Please select a batch to export.')
                ->danger()
                ->send();

            return;
        }

        $batch = ImportBatch::find($data['batch_id']);

        if (! $batch) {
            Notification::make()
                ->title('Error')
                ->body('Selected batch not found.')
                ->danger()
                ->send();

            return;
        }

        // Base export mode: import-field-mapping (default + the legacy "add validation
        // fields" option) or a custom template. Appending service/transit results is
        // now an orthogonal toggle that works with either base mode.
        $templateId = $data['template_id'] ?? '_import_mapping';
        $useImportMapping = in_array($templateId, ['_import_mapping', '_import_with_validation'], true);
        $appendServiceResults = ($data['append_service_results'] ?? false)
            || $templateId === '_import_with_validation';

        if (! $useImportMapping) {
            $template = ExportTemplate::find($templateId);
            if (! $template) {
                Notification::make()
                    ->title('Error')
                    ->body('Selected template not found.')
                    ->danger()
                    ->send();

                return;
            }
        }

        // Clear any previous export
        $batch->update([
            'export_file_path' => null,
            'export_status' => 'pending',
            'export_completed_at' => null,
        ]);

        // Refresh to get updated values and store for UI polling
        $batch->refresh();
        $this->selectedExportBatch = $batch;

        // Also update the stats
        $this->updateExportBatchStats((string) $batch->id);

        $filterStatus = $data['filter_status'] ?? 'all';
        $filterSource = $data['filter_source'] ?? 'all';
        $sortBy = $data['sort_by'] ?? 'original';
        $filename = $data['filename'] ?? null;

        Log::info('BatchProcessing: Dispatching export job', [
            'batch_id' => $batch->id,
            'use_import_mapping' => $useImportMapping,
            'append_service_results' => $appendServiceResults,
            'filter_status' => $filterStatus,
            'sort_by' => $sortBy,
        ]);

        // Dispatch background job
        ProcessExportBatch::dispatch(
            $batch,
            $useImportMapping ? null : (int) $templateId,
            $useImportMapping,
            $filterStatus,
            $filename,
            $sortBy,
            $appendServiceResults,
            $filterSource
        );

        Notification::make()
            ->title('Export Started')
            ->body('Your export is being generated in the background. The download link will appear when ready.')
            ->success()
            ->send();
    }

    public function downloadExport(int $batchId): ?BinaryFileResponse
    {
        $batch = ImportBatch::find($batchId);

        if (! $batch || ! $batch->export_file_path) {
            Notification::make()
                ->title('Error')
                ->body('Export file not found.')
                ->danger()
                ->send();

            return null;
        }

        $filePath = Storage::disk('local')->path($batch->export_file_path);

        if (! file_exists($filePath)) {
            Notification::make()
                ->title('Error')
                ->body('Export file no longer exists.')
                ->danger()
                ->send();

            return null;
        }

        return response()->download($filePath);
    }

    public function refreshExportStatus(): void
    {
        if ($this->selectedExportBatch) {
            $this->selectedExportBatch->refresh();
        }
    }

    public function getAvailableExportTemplates(): array
    {
        return ExportTemplate::orderBy('name')->pluck('name', 'id')->toArray();
    }

    public function getAvailableBatches(): array
    {
        return ImportBatch::where('status', ImportBatch::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->pluck('name', 'id')
            ->toArray();
    }
}
