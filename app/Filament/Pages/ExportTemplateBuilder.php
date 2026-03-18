<?php

namespace App\Filament\Pages;

use App\Models\CompanySetting;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use App\Services\ImportService;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class ExportTemplateBuilder extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static ?string $navigationLabel = 'Export Template Builder';

    protected static string|UnitEnum|null $navigationGroup = 'Templates';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Export Template Builder';

    protected string $view = 'filament.pages.export-template-builder';

    // Step tracking
    public int $step = 1;

    // Step 1: Settings form data
    public ?array $settingsData = [];

    // Step 2: Field mappings
    public array $fieldMappings = [];

    // Sample data for preview
    public array $sampleAddresses = [];

    // Available system fields
    public array $systemFields = [];

    // Uploaded file path
    public ?string $uploadedFilePath = null;

    public function mount(): void
    {
        $this->settingsForm->fill([
            'file_format' => ExportTemplate::FORMAT_CSV,
            'delimiter' => ',',
            'include_header' => true,
        ]);

        $this->systemFields = $this->getExportableFields();
    }

    public function settingsForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template Settings')
                    ->description('Configure your export template.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Template Name')
                            ->required()
                            ->placeholder('e.g., ePace Standard Export'),

                        Textarea::make('description')
                            ->label('Description')
                            ->placeholder('Optional description of this template'),

                        Select::make('file_format')
                            ->label('File Format')
                            ->options(ExportTemplate::getFileFormats())
                            ->default(ExportTemplate::FORMAT_CSV)
                            ->required(),

                        TextInput::make('delimiter')
                            ->label('Delimiter')
                            ->default(',')
                            ->maxLength(1)
                            ->helperText('For CSV files'),

                        Toggle::make('include_header')
                            ->label('Include Header Row')
                            ->default(true),
                    ]),

                Section::make('Data Source (Optional)')
                    ->description('Select a batch to show sample data in the field mapping preview.')
                    ->schema([
                        Select::make('source_batch_id')
                            ->label('Sample Data Batch')
                            ->options(function () {
                                return ImportBatch::query()
                                    ->where('status', ImportBatch::STATUS_COMPLETED)
                                    ->whereHas('addresses')
                                    ->orderByDesc('created_at')
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (ImportBatch $batch) => [
                                        $batch->id => "{$batch->display_name} ({$batch->addresses()->count()} addresses)",
                                    ]);
                            })
                            ->searchable()
                            ->placeholder('-- Select a batch for sample data --')
                            ->helperText('Optional: Select a batch to preview sample data while mapping'),
                    ]),

                Section::make('Import Column Headers (Optional)')
                    ->description('Upload an example file to automatically extract column headers, or skip to manually define columns.')
                    ->schema([
                        FileUpload::make('example_file')
                            ->label('Example File')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->maxSize(5120)
                            ->disk('local')
                            ->directory('export-templates')
                            ->helperText('Optional: Upload an example file to extract column headers (.xlsx, .xls, .csv)'),
                    ]),
            ])
            ->statePath('settingsData');
    }

    public function proceedToMapping(): void
    {
        $data = $this->settingsForm->getState();

        if (empty($data['name'])) {
            Notification::make()
                ->title('Error')
                ->body('Please enter a template name.')
                ->danger()
                ->send();

            return;
        }

        // Load sample addresses if batch selected
        if (! empty($data['source_batch_id'])) {
            $batch = ImportBatch::find($data['source_batch_id']);
            if ($batch) {
                $this->sampleAddresses = $batch->addresses()
                    ->with('latestCorrection')
                    ->limit(3)
                    ->get()
                    ->toArray();
            }
        }

        // Extract headers from uploaded file if provided
        if (! empty($data['example_file'])) {
            $filePath = is_array($data['example_file']) ? ($data['example_file'][0] ?? null) : $data['example_file'];

            if ($filePath && Storage::disk('local')->exists($filePath)) {
                $this->uploadedFilePath = $filePath;
                $this->extractHeadersFromFile($filePath);
            }
        }

        // If no headers extracted, start with empty mappings (user will add manually)
        if (empty($this->fieldMappings)) {
            // Add some default common fields
            $this->addDefaultMappings();
        }

        $this->step = 2;
    }

    protected function extractHeadersFromFile(string $filePath): void
    {
        try {
            $importService = app(ImportService::class);
            $storedFilePath = Storage::disk('local')->path($filePath);
            $file = new UploadedFile($storedFilePath, basename($filePath));

            $headers = $importService->parseHeaders($file);

            if (! empty($headers)) {
                $this->fieldMappings = [];
                foreach ($headers as $position => $header) {
                    $this->fieldMappings[] = [
                        'position' => $position,
                        'header' => $header,
                        'field' => $this->autoMatchExportField($header),
                    ];
                }

                Notification::make()
                    ->title('Headers Extracted')
                    ->body('Found '.count($headers).' columns from file.')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error Reading File')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function autoMatchExportField(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        // Common patterns for export fields
        $patterns = [
            'external_reference' => ['reference', 'ref', 'order', 'po', 'id'],
            'name' => ['name', 'recipient', 'contact', 'attn'],
            'company' => ['company', 'business', 'organization'],
            'corrected_address_line_1' => ['address', 'addr', 'street', 'add1', 'address1', 'addr1'],
            'corrected_address_line_2' => ['address 2', 'addr2', 'suite', 'apt', 'unit', 'add2', 'address2'],
            'corrected_city' => ['city', 'town'],
            'corrected_state' => ['state', 'province', 'st'],
            'corrected_postal_code' => ['zip', 'postal', 'postcode'],
            'country_code' => ['country', 'cc'],
        ];

        foreach ($patterns as $field => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $field;
                }
            }
        }

        // Unmatched fields default to PassThrough
        return 'passthrough';
    }

    protected function addDefaultMappings(): void
    {
        // Start with common export fields
        $defaults = [
            ['header' => 'Name', 'field' => 'name'],
            ['header' => 'Company', 'field' => 'company'],
            ['header' => 'Address', 'field' => 'corrected_address_line_1'],
            ['header' => 'Address 2', 'field' => 'corrected_address_line_2'],
            ['header' => 'City', 'field' => 'corrected_city'],
            ['header' => 'State', 'field' => 'corrected_state'],
            ['header' => 'Postal Code', 'field' => 'corrected_postal_code'],
            ['header' => 'Country', 'field' => 'country_code'],
        ];

        $this->fieldMappings = [];
        foreach ($defaults as $position => $mapping) {
            $this->fieldMappings[] = [
                'position' => $position,
                'header' => $mapping['header'],
                'field' => $mapping['field'],
            ];
        }
    }

    public function addColumn(): void
    {
        $position = count($this->fieldMappings);
        $this->fieldMappings[] = [
            'position' => $position,
            'header' => '',
            'field' => null,
        ];
    }

    public function removeColumn(int $index): void
    {
        if (isset($this->fieldMappings[$index])) {
            unset($this->fieldMappings[$index]);
            $this->fieldMappings = array_values($this->fieldMappings);

            // Re-index positions
            foreach ($this->fieldMappings as $i => &$mapping) {
                $mapping['position'] = $i;
            }
        }
    }

    public function moveColumnUp(int $index): void
    {
        if ($index > 0 && isset($this->fieldMappings[$index])) {
            $temp = $this->fieldMappings[$index - 1];
            $this->fieldMappings[$index - 1] = $this->fieldMappings[$index];
            $this->fieldMappings[$index] = $temp;

            // Update positions
            $this->fieldMappings[$index - 1]['position'] = $index - 1;
            $this->fieldMappings[$index]['position'] = $index;
        }
    }

    public function moveColumnDown(int $index): void
    {
        if ($index < count($this->fieldMappings) - 1 && isset($this->fieldMappings[$index])) {
            $temp = $this->fieldMappings[$index + 1];
            $this->fieldMappings[$index + 1] = $this->fieldMappings[$index];
            $this->fieldMappings[$index] = $temp;

            // Update positions
            $this->fieldMappings[$index + 1]['position'] = $index + 1;
            $this->fieldMappings[$index]['position'] = $index;
        }
    }

    public function backToSettings(): void
    {
        $this->step = 1;
    }

    public function saveTemplate(): void
    {
        $data = $this->settingsForm->getState();

        // Validate we have at least one mapping with both header and field
        $validMappings = collect($this->fieldMappings)
            ->filter(fn ($m) => ! empty($m['header']) && ! empty($m['field']))
            ->values()
            ->toArray();

        if (empty($validMappings)) {
            Notification::make()
                ->title('Error')
                ->body('Please add at least one column with a header name and mapped field.')
                ->danger()
                ->send();

            return;
        }

        // Convert passthrough fields to extra_X fields
        $nextExtraId = 1;
        $fieldLayout = [];

        foreach ($validMappings as $index => $mapping) {
            $field = $mapping['field'];

            // If this is a passthrough, assign the next available extra field
            if ($field === 'passthrough') {
                $field = "extra_{$nextExtraId}";
                $nextExtraId++;

                // Cap at 20 extra fields
                if ($nextExtraId > 20) {
                    Notification::make()
                        ->title('Warning')
                        ->body('Maximum of 20 PassThrough fields allowed. Some fields were not mapped.')
                        ->warning()
                        ->send();
                    $nextExtraId = 20;
                }
            }

            $fieldLayout[] = [
                'position' => $index,
                'field' => $field,
                'header' => $mapping['header'],
            ];
        }

        try {
            $template = ExportTemplate::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'target_system' => 'generic',
                'file_format' => $data['file_format'] ?? ExportTemplate::FORMAT_CSV,
                'delimiter' => $data['delimiter'] ?? ',',
                'include_header' => $data['include_header'] ?? true,
                'field_layout' => $fieldLayout,
                'is_shared' => false,
                'created_by' => auth()->id(),
            ]);

            // Clean up uploaded file
            if ($this->uploadedFilePath && Storage::disk('local')->exists($this->uploadedFilePath)) {
                Storage::disk('local')->delete($this->uploadedFilePath);
            }

            Notification::make()
                ->title('Template Saved')
                ->body("Export template '{$template->name}' has been created with ".count($fieldLayout).' columns.')
                ->success()
                ->send();

            // Redirect to template list
            $this->redirect(route('filament.admin.resources.export-templates.index'));

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error Saving Template')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getExportableFields(): array
    {
        // Start with empty/select option
        $fields = [
            '' => '-- Select Field --',
            'passthrough' => '↔ PassThrough (Auto-assign to Extra Field)',
        ];

        // Add standard fields
        $fields = array_merge($fields, ExportTemplate::getAvailableFields());

        // Add extra fields (dynamic count from settings)
        $extraFieldCount = CompanySetting::instance()->getExtraFieldCount();
        for ($i = 1; $i <= $extraFieldCount; $i++) {
            $fields["extra_{$i}"] = "Extra Field {$i}";
        }

        return $fields;
    }

    public function getSampleValue(int $addressIndex, string $field): ?string
    {
        if (empty($this->sampleAddresses) || ! isset($this->sampleAddresses[$addressIndex])) {
            return null;
        }

        $address = $this->sampleAddresses[$addressIndex];
        $correction = $address['latest_correction'] ?? null;

        return match ($field) {
            'external_reference' => $address['external_reference'] ?? null,
            'name' => $address['name'] ?? null,
            'company' => $address['company'] ?? null,
            'original_address_line_1' => $address['address_line_1'] ?? null,
            'original_address_line_2' => $address['address_line_2'] ?? null,
            'original_city' => $address['city'] ?? null,
            'original_state' => $address['state'] ?? null,
            'original_postal_code' => $address['postal_code'] ?? null,
            'corrected_address_line_1' => $correction['corrected_address_line_1'] ?? $address['address_line_1'] ?? null,
            'corrected_address_line_2' => $correction['corrected_address_line_2'] ?? $address['address_line_2'] ?? null,
            'corrected_city' => $correction['corrected_city'] ?? $address['city'] ?? null,
            'corrected_state' => $correction['corrected_state'] ?? $address['state'] ?? null,
            'corrected_postal_code' => $correction['corrected_postal_code'] ?? $address['postal_code'] ?? null,
            'corrected_postal_code_ext' => $correction['corrected_postal_code_ext'] ?? null,
            'full_postal_code' => $this->formatFullPostalCode($correction),
            'country_code' => $correction['corrected_country_code'] ?? $address['country_code'] ?? null,
            'validation_status' => $correction['validation_status'] ?? null,
            'is_residential' => isset($correction['is_residential']) ? ($correction['is_residential'] ? 'Yes' : 'No') : null,
            'classification' => $correction['classification'] ?? null,
            'confidence_score' => isset($correction['confidence_score']) ? number_format($correction['confidence_score'] * 100, 0).'%' : null,
            'carrier' => $correction['carrier']['name'] ?? null,
            'validated_at' => $correction['validated_at'] ?? null,
            'passthrough' => '[PassThrough]',
            default => $this->getExtraFieldValue($address, $field),
        };
    }

    protected function formatFullPostalCode(?array $correction): ?string
    {
        if (! $correction) {
            return null;
        }

        $postal = $correction['corrected_postal_code'] ?? null;
        $ext = $correction['corrected_postal_code_ext'] ?? null;

        if (! $postal) {
            return null;
        }

        return $ext ? "{$postal}-{$ext}" : $postal;
    }

    protected function getExtraFieldValue(array $address, string $field): ?string
    {
        if (str_starts_with($field, 'extra_')) {
            return $address[$field] ?? null;
        }

        return null;
    }
}
