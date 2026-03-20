<?php

namespace App\Services;

use App\Models\Address;
use App\Models\CompanySetting;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use App\Models\TransitTime;
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

        // Handle ship via code fields
        if (str_starts_with($field, 'ship_via_')) {
            return $this->getShipViaFieldValue($address, $field);
        }

        // Handle fastest service fields
        if (str_starts_with($field, 'fastest_')) {
            return $this->getFastestServiceFieldValue($address, $field);
        }

        // Handle legacy transit_ fields for backwards compatibility
        if (str_starts_with($field, 'transit_')) {
            return $this->getTransitTimeFieldValue($address, $field);
        }

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

            // Ship dates & recommendation fields
            'requested_ship_date' => $address->requested_ship_date?->format('Y-m-d'),
            'required_on_site_date' => $address->required_on_site_date?->format('Y-m-d'),
            'recommended_service' => $address->recommended_service,
            'estimated_delivery_date' => $address->estimated_delivery_date?->format('Y-m-d'),
            'can_meet_required_date' => $address->can_meet_required_date === null ? '' : ($address->can_meet_required_date ? 'Yes' : 'No'),

            // Alternative suggestion when ship_via doesn't meet deadline
            'suggested_service' => $address->suggested_service,
            'suggested_delivery_date' => $address->suggested_delivery_date?->format('Y-m-d'),

            // Distance - use stored value first, fallback to transit times
            'distance_miles' => $address->distance_miles !== null
                ? number_format((float) $address->distance_miles, 1)
                : $this->getDistanceValue($address),

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
     * Get ship via code field value.
     * Prefers stored calculated values, falls back to on-the-fly calculation.
     */
    protected function getShipViaFieldValue(Address $address, string $field): ?string
    {
        return match ($field) {
            'ship_via_code' => $address->ship_via_code,
            // Use stored values first (populated by ShippingRecommendationService)
            'ship_via_service' => $address->ship_via_service_name
                ?? $this->calculateShipViaService($address),
            'ship_via_transit_days' => $address->ship_via_transit_days
                ?? $this->calculateShipViaTransitDays($address),
            'ship_via_delivery_date' => $address->ship_via_delivery_date?->format('Y-m-d')
                ?? $this->calculateShipViaDeliveryDate($address),
            'ship_via_meets_deadline' => $address->ship_via_meets_deadline === null
                ? '' : ($address->ship_via_meets_deadline ? 'Yes' : 'No'),
            default => null,
        };
    }

    /**
     * Calculate ship via service name on-the-fly (fallback).
     */
    protected function calculateShipViaService(Address $address): ?string
    {
        if (! $address->relationLoaded('shipViaCodeRecord')) {
            $address->load('shipViaCodeRecord');
        }

        return $address->shipViaCodeRecord?->service_name;
    }

    /**
     * Calculate ship via transit days on-the-fly (fallback).
     */
    protected function calculateShipViaTransitDays(Address $address): ?string
    {
        if (! $address->relationLoaded('shipViaCodeRecord')) {
            $address->load('shipViaCodeRecord');
        }

        $shipViaCode = $address->shipViaCodeRecord;
        if (! $shipViaCode || ! $shipViaCode->service_type) {
            return null;
        }

        if (! $address->relationLoaded('transitTimes')) {
            $address->load('transitTimes');
        }

        $transitTime = $address->transitTimes->firstWhere('service_type', $shipViaCode->service_type);

        return $transitTime?->transit_range;
    }

    /**
     * Calculate ship via delivery date on-the-fly (fallback).
     */
    protected function calculateShipViaDeliveryDate(Address $address): ?string
    {
        if (! $address->relationLoaded('shipViaCodeRecord')) {
            $address->load('shipViaCodeRecord');
        }

        $shipViaCode = $address->shipViaCodeRecord;
        if (! $shipViaCode || ! $shipViaCode->service_type) {
            return null;
        }

        if (! $address->relationLoaded('transitTimes')) {
            $address->load('transitTimes');
        }

        $transitTime = $address->transitTimes->firstWhere('service_type', $shipViaCode->service_type);

        return $transitTime?->delivery_date?->format('Y-m-d');
    }

    /**
     * Get fastest service field value.
     * Prefers stored values, falls back to on-the-fly calculation.
     */
    protected function getFastestServiceFieldValue(Address $address, string $field): ?string
    {
        return match ($field) {
            // Use stored values first (populated by ShippingRecommendationService)
            'fastest_service' => $address->fastest_service
                ?? $this->calculateFastestService($address),
            'fastest_delivery_date' => $address->fastest_delivery_date?->format('Y-m-d')
                ?? $this->calculateFastestDeliveryDate($address),
            default => null,
        };
    }

    /**
     * Calculate fastest service on-the-fly (fallback).
     */
    protected function calculateFastestService(Address $address): ?string
    {
        if (! $address->relationLoaded('transitTimes')) {
            $address->load('transitTimes');
        }

        return $this->getFastestService($address->transitTimes)?->service_label;
    }

    /**
     * Calculate fastest delivery date on-the-fly (fallback).
     */
    protected function calculateFastestDeliveryDate(Address $address): ?string
    {
        if (! $address->relationLoaded('transitTimes')) {
            $address->load('transitTimes');
        }

        return $this->getFastestService($address->transitTimes)?->delivery_date?->format('Y-m-d');
    }

    /**
     * Get distance value from transit times.
     */
    protected function getDistanceValue(Address $address): ?string
    {
        if (! $address->relationLoaded('transitTimes')) {
            $address->load('transitTimes');
        }

        return $address->transitTimes->first()?->formatted_distance;
    }

    /**
     * Get transit time field value from an address.
     */
    protected function getTransitTimeFieldValue(Address $address, string $field): ?string
    {
        // Load transit times if not already loaded
        if (! $address->relationLoaded('transitTimes')) {
            $address->load('transitTimes');
        }

        $transitTimes = $address->transitTimes;

        if ($transitTimes->isEmpty()) {
            return null;
        }

        // Map field names to service types
        $serviceTypeMap = [
            'transit_ground' => 'FEDEX_GROUND',
            'transit_home_delivery' => 'GROUND_HOME_DELIVERY',
            'transit_express_saver' => 'FEDEX_EXPRESS_SAVER',
            'transit_2day' => 'FEDEX_2_DAY',
            'transit_2day_am' => 'FEDEX_2_DAY_AM',
            'transit_standard_overnight' => 'STANDARD_OVERNIGHT',
            'transit_priority_overnight' => 'PRIORITY_OVERNIGHT',
            'transit_first_overnight' => 'FIRST_OVERNIGHT',
        ];

        // Handle specific service transit times
        foreach ($serviceTypeMap as $prefix => $serviceType) {
            if (str_starts_with($field, $prefix)) {
                $transitTime = $transitTimes->firstWhere('service_type', $serviceType);

                if (! $transitTime) {
                    return null;
                }

                if (str_ends_with($field, '_days')) {
                    return $transitTime->transit_range;
                }
                if (str_ends_with($field, '_date')) {
                    return $transitTime->delivery_date?->format('Y-m-d');
                }
            }
        }

        // Handle special fields
        return match ($field) {
            'transit_fastest_service' => $this->getFastestService($transitTimes)?->service_label,
            'transit_fastest_delivery_date' => $this->getFastestService($transitTimes)?->delivery_date?->format('Y-m-d'),
            'transit_distance_miles' => $transitTimes->first()?->formatted_distance,
            default => null,
        };
    }

    /**
     * Get the fastest delivery service from transit times.
     */
    protected function getFastestService(Collection $transitTimes): ?TransitTime
    {
        // Priority order for fastest services (overnight first, then express, then ground)
        $priorityOrder = [
            'FIRST_OVERNIGHT',
            'PRIORITY_OVERNIGHT',
            'STANDARD_OVERNIGHT',
            'FEDEX_2_DAY_AM',
            'FEDEX_2_DAY',
            'FEDEX_EXPRESS_SAVER',
            'GROUND_HOME_DELIVERY',
            'FEDEX_GROUND',
        ];

        foreach ($priorityOrder as $serviceType) {
            $transitTime = $transitTimes->firstWhere('service_type', $serviceType);
            if ($transitTime) {
                return $transitTime;
            }
        }

        // Return first available if none match priority
        return $transitTimes->first();
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
