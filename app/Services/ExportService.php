<?php

namespace App\Services;

use App\Models\Address;
use App\Models\CompanySetting;
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
        // With denormalized schema, no eager loading needed!
        $addresses = $batch->addresses()->get();

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

        if ($template->include_header) {
            $headers = [];
            foreach ($fields as $field) {
                $headers[] = $field['header'] ?? $field['field'];
            }
            $rows[] = $headers;
        }

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
     * With denormalized schema, all data is directly on the address.
     */
    public function getFieldValue(Address $address, string $field): ?string
    {
        // Handle extra fields (stored in JSON)
        if (str_starts_with($field, 'extra_')) {
            return $address->getExtraField($field);
        }

        return match ($field) {
            // Original input address fields
            'external_reference' => $address->external_reference,
            'name', 'input_name' => $address->input_name,
            'company', 'input_company' => $address->input_company,
            'original_address_line_1', 'input_address_1' => $address->input_address_1,
            'original_address_line_2', 'input_address_2' => $address->input_address_2,
            'original_city', 'input_city' => $address->input_city,
            'original_state', 'input_state' => $address->input_state,
            'original_postal_code', 'input_postal' => $address->input_postal,
            'input_country' => $address->input_country,

            // Corrected/output address fields (directly on address now)
            'corrected_address_line_1', 'output_address_1' => $address->output_address_1,
            'corrected_address_line_2', 'output_address_2' => $address->output_address_2,
            'corrected_city', 'output_city' => $address->output_city,
            'corrected_state', 'output_state' => $address->output_state,
            'corrected_postal_code', 'output_postal' => $address->output_postal,
            'corrected_postal_code_ext', 'output_postal_ext' => $address->output_postal_ext,
            'full_postal_code' => $this->getFullPostalCode($address),
            'country_code', 'output_country' => $address->output_country ?? $address->input_country,

            // Validation fields (directly on address now)
            'validation_status' => $address->validation_status,
            'is_residential' => $address->is_residential === null ? '' : ($address->is_residential ? 'Yes' : 'No'),
            'classification' => $address->classification,
            'confidence_score' => $address->confidence_score ? number_format($address->confidence_score * 100, 0).'%' : null,
            'carrier' => $address->validatedByCarrier?->name,
            'validated_at' => $address->validated_at?->format('Y-m-d H:i:s'),

            // Ship dates & recommendation fields
            'requested_ship_date' => $address->requested_ship_date?->format('Y-m-d'),
            'required_on_site_date' => $address->required_on_site_date?->format('Y-m-d'),

            // Ship via fields (directly on address)
            'ship_via_code' => $address->ship_via_code,
            'previous_ship_via_code' => $address->previous_ship_via_code,
            'bestway_optimized' => $address->bestway_optimized === null ? '' : ($address->bestway_optimized ? 'Yes' : 'No'),
            'ship_via_service' => $address->ship_via_service,
            'ship_via_transit_days', 'ship_via_days' => $address->ship_via_days !== null ? (string) $address->ship_via_days : null,
            'ship_via_delivery_date', 'ship_via_date' => $address->ship_via_date?->format('Y-m-d'),
            'ship_via_meets_deadline' => $address->ship_via_meets_deadline === null ? '' : ($address->ship_via_meets_deadline ? 'Yes' : 'No'),

            // Fastest service fields (directly on address)
            'fastest_service' => $address->fastest_service,
            'fastest_days' => $address->fastest_days !== null ? (string) $address->fastest_days : null,
            'fastest_delivery_date', 'fastest_date' => $address->fastest_date?->format('Y-m-d'),

            // Ground service fields (directly on address)
            'ground_service' => $address->ground_service,
            'ground_days' => $address->ground_days !== null ? (string) $address->ground_days : null,
            'ground_date' => $address->ground_date?->format('Y-m-d'),

            // Distance
            'distance_miles' => $address->distance_miles !== null ? number_format((float) $address->distance_miles, 1) : null,

            // Legacy field names for backwards compatibility
            'recommended_service' => $address->fastest_service,
            'estimated_delivery_date' => $address->fastest_date?->format('Y-m-d'),
            'can_meet_required_date' => $address->ship_via_meets_deadline === null ? '' : ($address->ship_via_meets_deadline ? 'Yes' : 'No'),
            'suggested_service' => $address->ground_service,
            'suggested_delivery_date' => $address->ground_date?->format('Y-m-d'),

            // Legacy transit_ prefixed fields
            'transit_fastest_service' => $address->fastest_service,
            'transit_fastest_delivery_date' => $address->fastest_date?->format('Y-m-d'),
            'transit_distance_miles' => $address->distance_miles !== null ? number_format((float) $address->distance_miles, 1) : null,
            'transit_ground_days' => $address->ground_days !== null ? (string) $address->ground_days : null,
            'transit_ground_date' => $address->ground_date?->format('Y-m-d'),

            default => null,
        };
    }

    /**
     * Get full postal code with extension.
     */
    protected function getFullPostalCode(Address $address): ?string
    {
        if (! $address->output_postal) {
            return null;
        }

        if ($address->output_postal_ext) {
            return $address->output_postal.'-'.$address->output_postal_ext;
        }

        return $address->output_postal;
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
        set_time_limit(600);
        ini_set('memory_limit', '1G');

        $filename = $filename ?? $batch->display_name.'_validated_'.now()->format('Ymd_His');

        $mappings = $batch->field_mappings ?? [];
        $export = new ImportMappingExport($addresses, $mappings);

        return Excel::download($export, $filename.'.csv');
    }

    /**
     * Get the export field value for a system field, preferring corrected values.
     * Supports both old field names (address_line_1) and new field names (input_address_1).
     */
    public function getExportFieldValue(Address $address, string $systemField): ?string
    {
        // Handle extra fields
        if (str_starts_with($systemField, 'extra_')) {
            return $address->getExtraField($systemField);
        }

        // For address fields, prefer output (corrected) values if available
        return match ($systemField) {
            // New field names (input_*) - return corrected if available, else original
            'input_address_1' => $address->output_address_1 ?? $address->input_address_1,
            'input_address_2' => $address->output_address_2 ?? $address->input_address_2,
            'input_city' => $address->output_city ?? $address->input_city,
            'input_state' => $address->output_state ?? $address->input_state,
            'input_postal' => $address->output_postal ?? $address->input_postal,
            'input_country' => $address->output_country ?? $address->input_country,
            'input_name' => $address->input_name,
            'input_company' => $address->input_company,

            // Old field names (for backward compatibility)
            'address_line_1' => $address->output_address_1 ?? $address->input_address_1,
            'address_line_2' => $address->output_address_2 ?? $address->input_address_2,
            'city' => $address->output_city ?? $address->input_city,
            'state' => $address->output_state ?? $address->input_state,
            'postal_code' => $address->output_postal ?? $address->input_postal,
            'country_code' => $address->output_country ?? $address->input_country,
            'name' => $address->input_name,
            'company' => $address->input_company,

            // Other fields
            'external_reference' => $address->external_reference,
            'ship_via_code' => $address->ship_via_code,
            'requested_ship_date' => $address->requested_ship_date?->format('Y-m-d'),
            'required_on_site_date' => $address->required_on_site_date?->format('Y-m-d'),
            default => null,
        };
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

        // Add extra fields (dynamic count from settings)
        $extraFieldCount = CompanySetting::instance()->getExtraFieldCount();
        for ($i = 1; $i <= $extraFieldCount; $i++) {
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
                $row[] = '';
            } else {
                $row[] = $exportService->getExportFieldValue($address, $target);
            }
        }

        return $row;
    }
}
