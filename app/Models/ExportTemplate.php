<?php

namespace App\Models;

use Database\Factories\ExportTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportTemplate extends Model
{
    /** @use HasFactory<ExportTemplateFactory> */
    use HasFactory;

    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_FIXED_WIDTH = 'fixed_width';

    protected $fillable = [
        'name',
        'description',
        'target_system',
        'field_layout',
        'file_format',
        'delimiter',
        'include_header',
        'is_shared',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_layout' => 'array',
            'include_header' => 'boolean',
            'is_shared' => 'boolean',
        ];
    }

    /**
     * Get available target systems.
     *
     * @return array<string, string>
     */
    public static function getTargetSystems(): array
    {
        return [
            'epace' => 'ePace',
            'ups_worldship' => 'UPS WorldShip',
            'fedex_ship' => 'FedEx Ship Manager',
            'generic' => 'Generic/Custom',
        ];
    }

    /**
     * Get available file formats.
     *
     * @return array<string, string>
     */
    public static function getFileFormats(): array
    {
        return [
            self::FORMAT_CSV => 'CSV (Comma Separated)',
            self::FORMAT_XLSX => 'Excel (XLSX)',
            self::FORMAT_FIXED_WIDTH => 'Fixed Width Text',
        ];
    }

    /**
     * Get available address fields for export.
     *
     * @return array<string, string>
     */
    public static function getAvailableFields(): array
    {
        return [
            'external_reference' => 'External Reference',
            'name' => 'Recipient Name',
            'company' => 'Company',
            'original_address_line_1' => 'Original Address Line 1',
            'original_address_line_2' => 'Original Address Line 2',
            'original_city' => 'Original City',
            'original_state' => 'Original State',
            'original_postal_code' => 'Original Postal Code',
            'corrected_address_line_1' => 'Corrected Address Line 1',
            'corrected_address_line_2' => 'Corrected Address Line 2',
            'corrected_city' => 'Corrected City',
            'corrected_state' => 'Corrected State',
            'corrected_postal_code' => 'Corrected Postal Code',
            'corrected_postal_code_ext' => 'Corrected ZIP+4',
            'full_postal_code' => 'Full Postal Code (with +4)',
            'country_code' => 'Country Code',
            'validation_status' => 'Validation Status',
            'is_residential' => 'Is Residential',
            'classification' => 'Classification',
            'confidence_score' => 'Confidence Score',
            'carrier' => 'Carrier Used',
            'validated_at' => 'Validated At',

            // Ship Via Code Fields (based on their specified service)
            'ship_via_code' => 'Ship Via Code (Original)',
            'ship_via_service' => 'Ship Via Service Name',
            'ship_via_transit_days' => 'Ship Via Transit Days',
            'ship_via_delivery_date' => 'Ship Via Delivery Date',

            // Ship Dates & Service Recommendation
            'requested_ship_date' => 'Requested Ship Date',
            'required_on_site_date' => 'Required On-Site Date',
            'recommended_service' => 'Recommended Service',
            'estimated_delivery_date' => 'Estimated Delivery Date',
            'can_meet_required_date' => 'Can Meet Required Date',

            // Fastest Available Service
            'fastest_service' => 'Fastest Service Available',
            'fastest_delivery_date' => 'Fastest Delivery Date',

            // Distance
            'distance_miles' => 'Distance (Miles)',
        ];
    }

    /**
     * Get available sort options for export.
     *
     * @return array<string, string>
     */
    public static function getSortOptions(): array
    {
        return [
            'original' => 'Original Order',
            'delivery_date_asc' => 'Delivery Date (Earliest First)',
            'delivery_date_desc' => 'Delivery Date (Latest First)',
            'ship_via_code' => 'Ship Via Code',
            'state' => 'State',
            'postal_code' => 'Postal Code',
        ];
    }

    /**
     * Get the ordered field layout for export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderedFieldsAttribute(): array
    {
        $layout = $this->field_layout ?? [];

        usort($layout, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $layout;
    }

    /**
     * Get field count.
     */
    public function getFieldCountAttribute(): int
    {
        return count($this->field_layout ?? []);
    }

    // Relationships

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
