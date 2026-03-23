<?php

namespace App\Filament\Actions;

use App\Models\Carrier;
use App\Models\CompanySetting;
use App\Models\ImportBatch;
use App\Models\ImportFieldTemplate;
use App\Services\ImportService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader as CsvReader;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

/**
 * Import action for batch address import with field mapping modal.
 * Supports CSV and Excel files, template matching, and auto-mapping of extra fields.
 *
 * Based on Filament's ImportAction pattern from Attendance solution.
 */
class AddressImportAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'importAddresses';
    }

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
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Import Addresses');
        $this->modalHeading('Import Addresses');
        $this->modalDescription('Upload a CSV or Excel file and map the columns to address fields.');
        $this->modalSubmitActionLabel('Start Import');
        $this->icon(Heroicon::ArrowUpTray);
        $this->modalWidth('4xl');
        $this->closeModalByClickingAway(false);

        $this->schema(fn (): array => [
            Section::make('Upload File')
                ->schema([
                    FileUpload::make('file')
                        ->label('Address File')
                        ->placeholder('Click to browse or drag and drop')
                        ->acceptedFileTypes($this->getAcceptedFileTypes())
                        ->rules($this->getFileValidationRules())
                        ->storeFiles(false)
                        ->visibility('private')
                        ->required()
                        ->maxSize(10240)
                        ->helperText('Accepted formats: .xlsx, .xls, .csv (max 10MB)')
                        ->live()
                        ->afterStateUpdated(function (FileUpload $component, Component $livewire, Set $set, ?TemporaryUploadedFile $state): void {
                            Log::info('AddressImportAction: afterStateUpdated triggered', [
                                'state_type' => $state ? get_class($state) : 'null',
                                'is_temp_file' => $state instanceof TemporaryUploadedFile,
                            ]);

                            if (! $state instanceof TemporaryUploadedFile) {
                                Log::info('AddressImportAction: state is not TemporaryUploadedFile, returning');

                                return;
                            }

                            try {
                                $livewire->validateOnly($component->getStatePath());
                            } catch (ValidationException $exception) {
                                Log::error('AddressImportAction: validation failed', ['error' => $exception->getMessage()]);
                                $component->state([]);

                                throw $exception;
                            }

                            Log::info('AddressImportAction: getting file stream', [
                                'filename' => $state->getClientOriginalName(),
                                'extension' => $state->getClientOriginalExtension(),
                                'realPath' => $state->getRealPath(),
                                'path' => $state->path(),
                            ]);

                            $csvStream = $this->getUploadedFileStream($state);

                            if (! $csvStream) {
                                Log::error('AddressImportAction: could not get file stream');
                                Notification::make()
                                    ->title('Error')
                                    ->body('Could not read the uploaded file.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Log::info('AddressImportAction: got file stream, parsing CSV');

                            $csvReader = CsvReader::createFromStream($csvStream);
                            $csvReader->setHeaderOffset(0);

                            $csvColumns = $csvReader->getHeader();

                            Log::info('AddressImportAction: parsed headers', [
                                'headers' => $csvColumns,
                                'count' => count($csvColumns),
                            ]);

                            // Auto-match columns using guesses (case-insensitive)
                            $lowercaseCsvColumnValues = array_map(Str::lower(...), $csvColumns);
                            $lowercaseCsvColumnKeys = array_combine($lowercaseCsvColumnValues, $csvColumns);

                            // Check for matching template first
                            $matchedTemplate = $this->findMatchingTemplate($csvColumns);

                            if ($matchedTemplate) {
                                $columnMap = $this->getTemplateMappings($matchedTemplate, $csvColumns);
                                $set('columnMap', $columnMap);
                                $set('template_id', (string) $matchedTemplate->id);

                                Notification::make()
                                    ->title('Template Matched')
                                    ->body("Automatically applied template: {$matchedTemplate->name}")
                                    ->success()
                                    ->send();
                            } else {
                                // Auto-match using field guesses
                                $columnMap = [];
                                foreach ($this->getImportColumns() as $fieldName => $guesses) {
                                    $matchedColumn = Arr::first(
                                        array_intersect($lowercaseCsvColumnValues, $guesses)
                                    );
                                    $columnMap[$fieldName] = $matchedColumn ? $lowercaseCsvColumnKeys[$matchedColumn] : null;
                                }
                                $set('columnMap', $columnMap);

                                Log::info('AddressImportAction: auto-matched columns', [
                                    'columnMap' => $columnMap,
                                ]);
                            }

                            Log::info('AddressImportAction: afterStateUpdated completed successfully');
                        }),

                    Select::make('carrier_id')
                        ->label('Validation API')
                        ->options(Carrier::where('is_active', true)->pluck('name', 'id'))
                        ->required()
                        ->default(Carrier::where('is_active', true)->first()?->id)
                        ->helperText('Select the API to use for address validation'),
                ]),

            // Template selection
            Select::make('template_id')
                ->label('Load Template')
                ->placeholder('-- Select Template --')
                ->options(fn () => ImportFieldTemplate::orderBy('name')->pluck('name', 'id')->toArray())
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                    if (! $state) {
                        return;
                    }

                    $file = $get('file');
                    if (! $file instanceof TemporaryUploadedFile) {
                        return;
                    }

                    $csvStream = $this->getUploadedFileStream($file);
                    if (! $csvStream) {
                        return;
                    }

                    $csvReader = CsvReader::createFromStream($csvStream);
                    $csvReader->setHeaderOffset(0);
                    $csvColumns = $csvReader->getHeader();

                    $template = ImportFieldTemplate::find($state);
                    if ($template) {
                        $columnMap = $this->getTemplateMappings($template, $csvColumns);
                        $set('columnMap', $columnMap);

                        Notification::make()
                            ->title('Template Applied')
                            ->body("Applied mappings from '{$template->name}'")
                            ->success()
                            ->send();
                    }
                })
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),

            // Column mapping wizard UI - appears automatically when file is uploaded
            Fieldset::make('Column Mapping')
                ->columns(1)
                ->inlineLabel()
                ->schema(function (Get $get): array {
                    $csvFile = $get('file');

                    if (! $csvFile instanceof TemporaryUploadedFile) {
                        return [];
                    }

                    $csvStream = $this->getUploadedFileStream($csvFile);

                    if (! $csvStream) {
                        return [];
                    }

                    $csvReader = CsvReader::createFromStream($csvStream);
                    $csvReader->setHeaderOffset(0);

                    $csvColumns = $csvReader->getHeader();
                    $csvColumnOptions = array_combine($csvColumns, $csvColumns);

                    // Build select fields for each system field
                    return array_map(
                        fn (string $fieldName, string $label): Select => Select::make($fieldName)
                            ->label($label)
                            ->placeholder('-- Skip --')
                            ->options($csvColumnOptions)
                            ->searchable(),
                        array_keys($this->getSystemFields()),
                        array_values($this->getSystemFields())
                    );
                })
                ->statePath('columnMap')
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),

            // Save as template option
            Section::make('Save Template')
                ->schema([
                    Checkbox::make('saveAsTemplate')
                        ->label('Save this mapping as a template for future imports')
                        ->live(),

                    TextInput::make('templateName')
                        ->label('Template Name')
                        ->placeholder('Enter template name...')
                        ->required(fn (Get $get): bool => $get('saveAsTemplate') === true)
                        ->visible(fn (Get $get): bool => $get('saveAsTemplate') === true)
                        ->rules([
                            fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                if (empty($value)) {
                                    return;
                                }

                                if (ImportFieldTemplate::where('name', $value)->exists()) {
                                    $fail('A template with this name already exists.');
                                }
                            },
                        ]),
                ])
                ->collapsed()
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),

            // Data preview
            Section::make('Data Preview')
                ->schema([
                    View::make('filament.components.import-preview')
                        ->viewData(function (Get $get): array {
                            $csvFile = $get('file');

                            if (! $csvFile instanceof TemporaryUploadedFile) {
                                return ['headers' => [], 'rows' => []];
                            }

                            $csvStream = $this->getUploadedFileStream($csvFile);

                            if (! $csvStream) {
                                return ['headers' => [], 'rows' => []];
                            }

                            $csvReader = CsvReader::createFromStream($csvStream);
                            $csvReader->setHeaderOffset(0);

                            $headers = $csvReader->getHeader();
                            $records = $csvReader->getRecords();

                            $previewRows = [];
                            $count = 0;
                            foreach ($records as $record) {
                                $previewRows[] = array_values($record);
                                $count++;
                                if ($count >= 3) {
                                    break;
                                }
                            }

                            return ['headers' => $headers, 'rows' => $previewRows];
                        }),
                ])
                ->collapsible()
                ->collapsed()
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),
        ]);

        $this->action(function (array $data): void {
            $this->processImport($data);
        });

        $this->color('primary');
    }

    /**
     * Get file validation rules.
     */
    protected function getFileValidationRules(): array
    {
        $rules = [];
        $rules[] = 'extensions:csv,txt,xlsx,xls';

        // Add duplicate column check
        $rules[] = fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            $csvStream = $this->getUploadedFileStream($value);

            if (! $csvStream) {
                return;
            }

            $csvReader = CsvReader::createFromStream($csvStream);
            $csvReader->setHeaderOffset(0);

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

            if (! empty($filledDuplicateCsvColumns)) {
                $fail('Duplicate column headers found: '.implode(', ', $filledDuplicateCsvColumns));
            }
        };

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

        // For CSV files, get the real path
        $realPath = $file->getRealPath();

        if ($realPath && file_exists($realPath)) {
            return fopen($realPath, 'r');
        }

        // Fallback to path()
        $path = $file->path();
        if ($path && file_exists($path)) {
            return fopen($path, 'r');
        }

        return false;
    }

    /**
     * Convert Excel file to CSV and return as stream.
     *
     * @return resource|false
     */
    protected function convertExcelToStream(TemporaryUploadedFile $file)
    {
        $originalPath = $file->getRealPath() ?: $file->path();

        if (! $originalPath || ! file_exists($originalPath)) {
            return false;
        }

        $spreadsheet = IOFactory::load($originalPath);
        $csvWriter = new Csv($spreadsheet);
        $csvWriter->setDelimiter(',');
        $csvWriter->setEnclosure('"');
        $csvWriter->setLineEnding("\n");
        $csvWriter->setSheetIndex(0);

        $tempCsvPath = sys_get_temp_dir().'/'.uniqid('address_import_').'.csv';
        $csvWriter->save($tempCsvPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return fopen($tempCsvPath, 'r');
    }

    /**
     * Get import columns with their guess values for auto-matching.
     *
     * @return array<string, array<string>>
     */
    protected function getImportColumns(): array
    {
        return [
            'name' => ['name', 'recipient', 'recipient name', 'contact', 'contact name', 'shiptoname', 'recipient_name'],
            'company' => ['company', 'company name', 'business', 'organization', 'shiptocontact', 'company_name'],
            'address_line_1' => ['address', 'address1', 'street', 'street1', 'addr1', 'address line 1', 'shiptoaddressline1', 'addressline1', 'address_line_1'],
            'address_line_2' => ['address2', 'street2', 'addr2', 'apt', 'suite', 'unit', 'address line 2', 'shiptoaddressline2', 'addressline2', 'address_line_2'],
            'city' => ['city', 'town', 'shiptocity'],
            'state' => ['state', 'province', 'st', 'region', 'shiptostate'],
            'postal_code' => ['zip', 'zipcode', 'zip code', 'postal', 'postal code', 'postalcode', 'shiptozipcode', 'postal_code'],
            'country_code' => ['country', 'country code', 'countrycode', 'shiptocountry', 'country_code'],
            'external_reference' => ['reference', 'ref', 'order', 'order id', 'orderid', 'external reference', 'po', 'po number', 'external_reference'],
        ];
    }

    /**
     * Get available system fields for mapping.
     *
     * @return array<string, string>
     */
    protected function getSystemFields(): array
    {
        $fields = [
            'input_name' => 'Recipient Name',
            'input_company' => 'Company',
            'input_address_1' => 'Address Line 1',
            'input_address_2' => 'Address Line 2',
            'input_city' => 'City',
            'input_state' => 'State',
            'input_postal' => 'Postal Code',
            'input_country' => 'Country Code',
            'external_reference' => 'External Reference',
        ];

        // Add extra fields (dynamic count from settings)
        $extraFieldCount = CompanySetting::instance()->getExtraFieldCount();
        for ($i = 1; $i <= $extraFieldCount; $i++) {
            $fields["extra_{$i}"] = "Extra Field {$i}";
        }

        return $fields;
    }

    /**
     * Find a template that matches the file headers.
     */
    protected function findMatchingTemplate(array $headers): ?ImportFieldTemplate
    {
        $normalizedHeaders = array_map(fn ($h) => strtolower(trim($h)), $headers);

        $templates = ImportFieldTemplate::all();

        foreach ($templates as $template) {
            $mappings = $template->field_mappings ?? [];

            if (empty($mappings)) {
                continue;
            }

            $templateHeaders = array_map(
                fn ($m) => strtolower(trim($m['source'] ?? '')),
                $mappings
            );

            $matchCount = count(array_intersect($normalizedHeaders, $templateHeaders));
            $templateHeaderCount = count(array_filter($templateHeaders));

            if ($templateHeaderCount > 0 && ($matchCount / $templateHeaderCount) >= 0.8) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Get mappings from a template for the given headers.
     */
    protected function getTemplateMappings(ImportFieldTemplate $template, array $csvColumns): array
    {
        $mappings = $template->field_mappings ?? [];
        $columnMap = [];

        // Initialize all system fields to null
        foreach (array_keys($this->getSystemFields()) as $fieldName) {
            $columnMap[$fieldName] = null;
        }

        // Apply template mappings
        foreach ($mappings as $mapping) {
            $source = $mapping['source'] ?? '';
            $target = $mapping['target'] ?? '';

            if (empty($target)) {
                continue;
            }

            // Find matching CSV column (case-insensitive)
            foreach ($csvColumns as $csvColumn) {
                if (strtolower(trim($csvColumn)) === strtolower(trim($source))) {
                    $columnMap[$target] = $csvColumn;
                    break;
                }
            }
        }

        return $columnMap;
    }

    /**
     * Process the import with the given data.
     */
    protected function processImport(array $data): void
    {
        /** @var TemporaryUploadedFile $file */
        $file = $data['file'];
        $carrierId = $data['carrier_id'];
        $columnMap = $data['columnMap'] ?? [];
        $saveAsTemplate = $data['saveAsTemplate'] ?? false;
        $templateName = $data['templateName'] ?? null;

        // Get CSV columns for extra field assignment
        $csvStream = $this->getUploadedFileStream($file);
        $csvReader = CsvReader::createFromStream($csvStream);
        $csvReader->setHeaderOffset(0);
        $csvColumns = $csvReader->getHeader();

        // Build reverse map (CSV column -> system field)
        $mappedCsvColumns = array_filter($columnMap);
        $unmappedCsvColumns = array_diff($csvColumns, $mappedCsvColumns);

        // Auto-assign unmapped columns to extra fields
        $columnMap = $this->autoAssignExtraFields($columnMap, $unmappedCsvColumns);

        // Save template if requested
        if ($saveAsTemplate && $templateName) {
            $this->saveTemplate($templateName, $columnMap, $csvColumns);
        }

        // Store the file
        $storedPath = $file->store('imports', 'local');
        $storedFilePath = Storage::disk('local')->path($storedPath);

        // Create batch record
        $batch = ImportBatch::create([
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'status' => ImportBatch::STATUS_PROCESSING,
            'carrier_id' => $carrierId,
            'imported_by' => auth()->id(),
            'started_at' => now(),
        ]);

        try {
            $uploadedFile = new UploadedFile($storedFilePath, $file->getClientOriginalName());
            $importService = app(ImportService::class);

            // Convert column map to the format ImportService expects
            // columnMap is: system_field => csv_column
            // ImportService expects: [{position, source, target}]
            $mappings = [];
            foreach ($csvColumns as $position => $csvColumn) {
                // Find which system field this CSV column maps to
                $target = '';
                foreach ($columnMap as $systemField => $mappedCsvColumn) {
                    if ($mappedCsvColumn === $csvColumn) {
                        $target = $systemField;
                        break;
                    }
                }

                $mappings[] = [
                    'position' => $position,
                    'source' => $csvColumn,
                    'target' => $target,
                ];
            }

            $totalRows = count($importService->parseRows($uploadedFile));
            $batch->update(['total_rows' => $totalRows]);

            $addresses = $importService->createAddressesFromFile($uploadedFile, $batch, $mappings);

            $batch->update([
                'processed_rows' => $addresses->count(),
                'successful_rows' => $addresses->count(),
                'status' => ImportBatch::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            Notification::make()
                ->title('Import Complete')
                ->body("Successfully imported {$addresses->count()} addresses.")
                ->success()
                ->send();

        } catch (\Exception $e) {
            Log::error('Address import failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $batch->update([
                'status' => ImportBatch::STATUS_FAILED,
            ]);

            Notification::make()
                ->title('Import Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Auto-assign unmapped CSV columns to extra fields.
     */
    protected function autoAssignExtraFields(array $columnMap, array $unmappedCsvColumns): array
    {
        $usedExtraFields = [];

        // Find which extra fields are already used
        foreach ($columnMap as $fieldName => $csvColumn) {
            if ($csvColumn && str_starts_with($fieldName, 'extra_')) {
                $usedExtraFields[] = $fieldName;
            }
        }

        $nextExtraIndex = 1;

        // Assign unmapped columns to extra fields
        foreach ($unmappedCsvColumns as $csvColumn) {
            while (in_array("extra_{$nextExtraIndex}", $usedExtraFields) && $nextExtraIndex <= 20) {
                $nextExtraIndex++;
            }

            if ($nextExtraIndex <= 20) {
                $columnMap["extra_{$nextExtraIndex}"] = $csvColumn;
                $usedExtraFields[] = "extra_{$nextExtraIndex}";
                $nextExtraIndex++;
            }
        }

        return $columnMap;
    }

    /**
     * Save the current mapping as a template.
     */
    protected function saveTemplate(string $name, array $columnMap, array $csvColumns): void
    {
        // Convert columnMap (system_field => csv_column) to field_mappings format
        $mappings = [];

        foreach ($columnMap as $systemField => $csvColumn) {
            if ($csvColumn) {
                $mappings[] = [
                    'source' => $csvColumn,
                    'target' => $systemField,
                ];
            }
        }

        ImportFieldTemplate::create([
            'name' => $name,
            'description' => 'Created from import on '.now()->format('Y-m-d H:i'),
            'field_mappings' => $mappings,
            'is_default' => false,
            'created_by' => auth()->id(),
        ]);

        Notification::make()
            ->title('Template Saved')
            ->body("Template '{$name}' has been saved.")
            ->success()
            ->send();
    }
}
