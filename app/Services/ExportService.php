<?php

namespace App\Services;

use App\Models\Address;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportService
{
    /**
     * Export addresses from a batch using a template.
     */
    public function exportBatch(
        ImportBatch $batch,
        ExportTemplate $template,
        ?string $filename = null
    ): BinaryFileResponse {
        $addresses = $batch->addresses()->with('latestCorrection.carrier')->get();

        return $this->export($addresses, $template, $filename ?? $this->generateFilename($batch, $template));
    }

    /**
     * Export a collection of addresses using a template.
     */
    public function export(
        Collection $addresses,
        ExportTemplate $template,
        ?string $filename = null
    ): BinaryFileResponse {
        $filename = $filename ?? 'export_'.now()->format('Y-m-d_His');
        $extension = $this->getExtension($template->file_format);

        $export = new AddressExport($addresses, $template);

        return Excel::download($export, $filename.'.'.$extension);
    }

    /**
     * Get file data as array for streaming/preview.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExportData(Collection $addresses, ExportTemplate $template): array
    {
        $fields = $template->ordered_fields;
        $rows = [];

        // Add header row if enabled
        if ($template->include_header) {
            $headers = [];
            foreach ($fields as $field) {
                $headers[] = $field['header'] ?? $field['field'];
            }
            $rows[] = $headers;
        }

        // Add data rows
        foreach ($addresses as $address) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $this->getFieldValue($address, $field['field']);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get a field value from an address record.
     */
    public function getFieldValue(Address $address, string $field): ?string
    {
        $correction = $address->latestCorrection;

        return match ($field) {
            // Original address fields
            'external_reference' => $address->external_reference,
            'name' => $address->name,
            'company' => $address->company,
            'original_address_line_1' => $address->address_line_1,
            'original_address_line_2' => $address->address_line_2,
            'original_city' => $address->city,
            'original_state' => $address->state,
            'original_postal_code' => $address->postal_code,
            'country_code' => $address->country_code ?? $correction?->corrected_country_code,

            // Corrected address fields
            'corrected_address_line_1' => $correction?->corrected_address_line_1,
            'corrected_address_line_2' => $correction?->corrected_address_line_2,
            'corrected_city' => $correction?->corrected_city,
            'corrected_state' => $correction?->corrected_state,
            'corrected_postal_code' => $correction?->corrected_postal_code,
            'corrected_postal_code_ext' => $correction?->corrected_postal_code_ext,
            'full_postal_code' => $correction?->getFullPostalCode(),

            // Validation fields
            'validation_status' => $correction?->validation_status,
            'is_residential' => $correction?->is_residential ? 'Yes' : 'No',
            'classification' => $correction?->classification,
            'confidence_score' => $correction?->confidence_score ? number_format($correction->confidence_score * 100, 0).'%' : null,
            'carrier' => $correction?->carrier?->name,
            'validated_at' => $correction?->validated_at?->format('Y-m-d H:i:s'),

            // Extra fields (pass-through)
            'extra_1' => $address->extra_1,
            'extra_2' => $address->extra_2,
            'extra_3' => $address->extra_3,
            'extra_4' => $address->extra_4,
            'extra_5' => $address->extra_5,
            'extra_6' => $address->extra_6,
            'extra_7' => $address->extra_7,
            'extra_8' => $address->extra_8,
            'extra_9' => $address->extra_9,
            'extra_10' => $address->extra_10,
            'extra_11' => $address->extra_11,
            'extra_12' => $address->extra_12,
            'extra_13' => $address->extra_13,
            'extra_14' => $address->extra_14,
            'extra_15' => $address->extra_15,
            'extra_16' => $address->extra_16,
            'extra_17' => $address->extra_17,
            'extra_18' => $address->extra_18,
            'extra_19' => $address->extra_19,
            'extra_20' => $address->extra_20,

            default => null,
        };
    }

    /**
     * Export addresses using the import batch's field mappings.
     * Uses corrected address values where available.
     */
    public function exportUsingImportMapping(
        Collection $addresses,
        ImportBatch $batch,
        ?string $filename = null
    ): BinaryFileResponse {
        // Increase limits for large exports
        set_time_limit(600); // 10 minutes
        ini_set('memory_limit', '1G');

        $filename = $filename ?? $batch->display_name.'_validated_'.now()->format('Ymd_His');

        $mappings = $batch->field_mappings ?? [];
        $export = new ImportMappingExport($addresses, $mappings);

        return Excel::download($export, $filename.'.csv');
    }

    /**
     * Get the export field value for a system field, preferring corrected values.
     */
    public function getExportFieldValue(Address $address, string $systemField): ?string
    {
        $correction = $address->latestCorrection;

        // For address fields, prefer corrected values if available
        return match ($systemField) {
            'address_line_1' => $correction?->corrected_address_line_1 ?? $address->address_line_1,
            'address_line_2' => $correction?->corrected_address_line_2 ?? $address->address_line_2,
            'city' => $correction?->corrected_city ?? $address->city,
            'state' => $correction?->corrected_state ?? $address->state,
            'postal_code' => $correction?->corrected_postal_code ?? $address->postal_code,
            'country_code' => $correction?->corrected_country_code ?? $address->country_code,
            'name' => $address->name,
            'company' => $address->company,
            'external_reference' => $address->external_reference,
            // Extra fields pass through (including any unmapped fields like phone)
            default => $this->getExtraField($address, $systemField),
        };
    }

    /**
     * Get an extra field value by name.
     */
    protected function getExtraField(Address $address, string $field): ?string
    {
        if (str_starts_with($field, 'extra_')) {
            return $address->{$field} ?? null;
        }

        return null;
    }

    /**
     * Get file extension for format.
     */
    protected function getExtension(string $format): string
    {
        return match ($format) {
            ExportTemplate::FORMAT_CSV => 'csv',
            ExportTemplate::FORMAT_XLSX => 'xlsx',
            ExportTemplate::FORMAT_FIXED_WIDTH => 'txt',
            default => 'csv',
        };
    }

    /**
     * Generate a filename for the export.
     */
    protected function generateFilename(ImportBatch $batch, ExportTemplate $template): string
    {
        $batchName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $batch->display_name);
        $templateName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $template->name);

        return "{$batchName}_{$templateName}_".now()->format('Ymd_His');
    }

    /**
     * Get all available export fields with their labels.
     *
     * @return array<string, string>
     */
    public static function getAvailableFields(): array
    {
        $fields = ExportTemplate::getAvailableFields();

        // Add extra fields
        for ($i = 1; $i <= 20; $i++) {
            $fields["extra_{$i}"] = "Extra Field {$i}";
        }

        return $fields;
    }
}

/**
 * Laravel Excel export class for addresses using templates.
 */
class AddressExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        protected Collection $addresses,
        protected ExportTemplate $template
    ) {}

    public function collection(): Collection
    {
        return $this->addresses;
    }

    public function headings(): array
    {
        if (! $this->template->include_header) {
            return [];
        }

        $headers = [];
        foreach ($this->template->ordered_fields as $field) {
            $headers[] = $field['header'] ?? $field['field'];
        }

        return $headers;
    }

    /**
     * @param  Address  $address
     */
    public function map($address): array
    {
        $exportService = app(ExportService::class);
        $row = [];

        foreach ($this->template->ordered_fields as $field) {
            $row[] = $exportService->getFieldValue($address, $field['field']);
        }

        return $row;
    }
}

/**
 * Laravel Excel export class using import field mappings.
 * Exports with the same column structure as the original import file.
 */
class ImportMappingExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  array<array{source: string, target: string, position: int}>  $mappings
     */
    public function __construct(
        protected Collection $addresses,
        protected array $mappings
    ) {
        // Sort mappings by position
        usort($this->mappings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
    }

    public function collection(): Collection
    {
        return $this->addresses;
    }

    public function headings(): array
    {
        $headers = [];
        foreach ($this->mappings as $mapping) {
            $headers[] = $mapping['source'] ?? '';
        }

        return $headers;
    }

    /**
     * @param  Address  $address
     */
    public function map($address): array
    {
        $exportService = app(ExportService::class);
        $row = [];

        foreach ($this->mappings as $mapping) {
            $target = $mapping['target'] ?? '';

            if (empty($target)) {
                // Unmapped column - output empty
                $row[] = '';
            } else {
                // Get the value using the export field value method (prefers corrected)
                $row[] = $exportService->getExportFieldValue($address, $target);
            }
        }

        return $row;
    }
}
