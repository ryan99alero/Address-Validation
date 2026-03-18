<?php

namespace App\Models;

use Database\Factories\ShipViaCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipViaCode extends Model
{
    /** @use HasFactory<ShipViaCodeFactory> */
    use HasFactory;

    /**
     * Common carrier shorthand codes mapped to service types.
     * Used for automatic lookup when importing.
     */
    public const CARRIER_CODE_MAP = [
        // FedEx Domestic codes
        'FDG' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_GROUND'],
        'FHD' => ['carrier' => 'fedex', 'service_type' => 'GROUND_HOME_DELIVERY'],
        'FES' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_EXPRESS_SAVER'],
        'F2D' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_2_DAY'],
        'F2A' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_2_DAY_AM'],
        'FSO' => ['carrier' => 'fedex', 'service_type' => 'STANDARD_OVERNIGHT'],
        'FPO' => ['carrier' => 'fedex', 'service_type' => 'PRIORITY_OVERNIGHT'],
        'FFO' => ['carrier' => 'fedex', 'service_type' => 'FIRST_OVERNIGHT'],
        'FSP' => ['carrier' => 'fedex', 'service_type' => 'SMART_POST'],
        // FedEx Freight codes
        'F1F' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_1_DAY_FREIGHT'],
        'F2F' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_2_DAY_FREIGHT'],
        'F3F' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_3_DAY_FREIGHT'],
        // FedEx International codes
        'FIP' => ['carrier' => 'fedex', 'service_type' => 'INTERNATIONAL_PRIORITY'],
        'FIE' => ['carrier' => 'fedex', 'service_type' => 'INTERNATIONAL_ECONOMY'],
        'FIF' => ['carrier' => 'fedex', 'service_type' => 'INTERNATIONAL_FIRST'],
        'FIPF' => ['carrier' => 'fedex', 'service_type' => 'INTERNATIONAL_PRIORITY_FREIGHT'],
        'FIEF' => ['carrier' => 'fedex', 'service_type' => 'INTERNATIONAL_ECONOMY_FREIGHT'],
        'FEIP' => ['carrier' => 'fedex', 'service_type' => 'EUROPE_FIRST_INTERNATIONAL_PRIORITY'],
        'FIG' => ['carrier' => 'fedex', 'service_type' => 'FEDEX_INTERNATIONAL_GROUND'],

        // UPS Domestic codes
        '03' => ['carrier' => 'ups', 'service_type' => 'GND'],
        'GND' => ['carrier' => 'ups', 'service_type' => 'GND'],
        '02' => ['carrier' => 'ups', 'service_type' => '2DA'],
        '2DA' => ['carrier' => 'ups', 'service_type' => '2DA'],
        '59' => ['carrier' => 'ups', 'service_type' => '2DM'],
        '2DM' => ['carrier' => 'ups', 'service_type' => '2DM'],
        '12' => ['carrier' => 'ups', 'service_type' => '3DS'],
        '3DS' => ['carrier' => 'ups', 'service_type' => '3DS'],
        '01' => ['carrier' => 'ups', 'service_type' => 'NDA'],
        'NDA' => ['carrier' => 'ups', 'service_type' => 'NDA'],
        '13' => ['carrier' => 'ups', 'service_type' => 'NDS'],
        'NDS' => ['carrier' => 'ups', 'service_type' => 'NDS'],
        '14' => ['carrier' => 'ups', 'service_type' => 'NDM'],
        'NDM' => ['carrier' => 'ups', 'service_type' => 'NDM'],
        // UPS Standard/International codes
        '11' => ['carrier' => 'ups', 'service_type' => 'STD'],
        'STD' => ['carrier' => 'ups', 'service_type' => 'STD'],
        '07' => ['carrier' => 'ups', 'service_type' => 'WXS'],
        'WXS' => ['carrier' => 'ups', 'service_type' => 'WXS'],
        '08' => ['carrier' => 'ups', 'service_type' => 'WXD'],
        'WXD' => ['carrier' => 'ups', 'service_type' => 'WXD'],
        '54' => ['carrier' => 'ups', 'service_type' => 'WXSP'],
        'WXSP' => ['carrier' => 'ups', 'service_type' => 'WXSP'],
        '65' => ['carrier' => 'ups', 'service_type' => 'WSV'],
        'WSV' => ['carrier' => 'ups', 'service_type' => 'WSV'],
        '93' => ['carrier' => 'ups', 'service_type' => 'SP'],
        'SP' => ['carrier' => 'ups', 'service_type' => 'SP'],
    ];

    /**
     * Human-readable names for service types.
     */
    public const SERVICE_TYPE_LABELS = [
        // FedEx Domestic
        'FEDEX_GROUND' => 'FedEx Ground',
        'GROUND_HOME_DELIVERY' => 'FedEx Home Delivery',
        'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
        'FEDEX_2_DAY' => 'FedEx 2Day',
        'FEDEX_2_DAY_AM' => 'FedEx 2Day A.M.',
        'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
        'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
        'FIRST_OVERNIGHT' => 'FedEx First Overnight',
        'SMART_POST' => 'FedEx SmartPost',
        // FedEx Freight
        'FEDEX_1_DAY_FREIGHT' => 'FedEx 1 Day Freight',
        'FEDEX_2_DAY_FREIGHT' => 'FedEx 2 Day Freight',
        'FEDEX_3_DAY_FREIGHT' => 'FedEx 3 Day Freight',
        // FedEx International
        'INTERNATIONAL_PRIORITY' => 'FedEx International Priority',
        'INTERNATIONAL_ECONOMY' => 'FedEx International Economy',
        'INTERNATIONAL_FIRST' => 'FedEx International First',
        'INTERNATIONAL_PRIORITY_FREIGHT' => 'FedEx International Priority Freight',
        'INTERNATIONAL_ECONOMY_FREIGHT' => 'FedEx International Economy Freight',
        'EUROPE_FIRST_INTERNATIONAL_PRIORITY' => 'FedEx Europe First International Priority',
        'FEDEX_INTERNATIONAL_GROUND' => 'FedEx International Ground',

        // UPS Domestic
        'GND' => 'UPS Ground',
        '2DA' => 'UPS 2nd Day Air',
        '2DM' => 'UPS 2nd Day Air A.M.',
        '3DS' => 'UPS 3 Day Select',
        'NDA' => 'UPS Next Day Air',
        'NDS' => 'UPS Next Day Air Saver',
        'NDM' => 'UPS Next Day Air Early',
        // UPS Standard/International
        'STD' => 'UPS Standard',
        'WXS' => 'UPS Worldwide Express',
        'WXD' => 'UPS Worldwide Expedited',
        'WXSP' => 'UPS Worldwide Express Plus',
        'WSV' => 'UPS Worldwide Saver',
        'SP' => 'UPS SurePost',
    ];

    protected $fillable = [
        'code',
        'carrier_code',
        'alternate_codes',
        'carrier_id',
        'service_type',
        'service_name',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alternate_codes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get all lookup codes for this ship via code (code + carrier_code + alternate_codes).
     *
     * @return array<string>
     */
    public function getAllLookupCodes(): array
    {
        $codes = [];

        if ($this->code) {
            $codes[] = $this->code;
        }

        if ($this->carrier_code) {
            $codes[] = $this->carrier_code;
        }

        if ($this->alternate_codes) {
            $codes = array_merge($codes, $this->alternate_codes);
        }

        if ($this->service_type) {
            $codes[] = $this->service_type;
        }

        return array_unique(array_filter($codes));
    }

    /**
     * Look up a ship via code by code, carrier code, alternate codes, or service type.
     * Checks all possible code fields for a match.
     * Returns the matching ShipViaCode or null.
     */
    public static function lookup(string $code): ?self
    {
        $upperCode = strtoupper($code);

        // First try exact match on user's custom code (case-sensitive)
        $match = static::where('code', $code)->where('is_active', true)->first();

        if ($match) {
            return $match;
        }

        // Try carrier code match (case-insensitive)
        $match = static::where('carrier_code', $upperCode)->where('is_active', true)->first();

        if ($match) {
            return $match;
        }

        // Try alternate codes match (JSON contains)
        $match = static::where('is_active', true)
            ->whereJsonContains('alternate_codes', $code)
            ->first();

        if (! $match) {
            // Also try uppercase version
            $match = static::where('is_active', true)
                ->whereJsonContains('alternate_codes', $upperCode)
                ->first();
        }

        if ($match) {
            return $match;
        }

        // Try service type match
        $match = static::where('service_type', $upperCode)->where('is_active', true)->first();

        if ($match) {
            return $match;
        }

        // Check if it's a known carrier code we can auto-resolve
        if (isset(self::CARRIER_CODE_MAP[$upperCode])) {
            $mapped = self::CARRIER_CODE_MAP[$upperCode];

            // Find based on mapped values
            return static::where('service_type', $mapped['service_type'])
                ->whereHas('carrier', fn ($q) => $q->where('slug', $mapped['carrier']))
                ->where('is_active', true)
                ->first();
        }

        return null;
    }

    /**
     * Get the carrier slug for this ship via code.
     */
    public function getCarrierSlugAttribute(): ?string
    {
        return $this->carrier?->slug;
    }

    /**
     * Get display label for the service type.
     */
    public function getServiceLabelAttribute(): string
    {
        return self::SERVICE_TYPE_LABELS[$this->service_type] ?? $this->service_name;
    }

    /**
     * Get available service types for a carrier.
     *
     * @return array<string, string>
     */
    public static function getServiceTypesForCarrier(string $carrierSlug): array
    {
        if ($carrierSlug === 'fedex') {
            return [
                // Domestic
                'FEDEX_GROUND' => 'FedEx Ground',
                'GROUND_HOME_DELIVERY' => 'FedEx Home Delivery',
                'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
                'FEDEX_2_DAY' => 'FedEx 2Day',
                'FEDEX_2_DAY_AM' => 'FedEx 2Day A.M.',
                'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
                'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
                'FIRST_OVERNIGHT' => 'FedEx First Overnight',
                'SMART_POST' => 'FedEx SmartPost',
                // Freight
                'FEDEX_1_DAY_FREIGHT' => 'FedEx 1 Day Freight',
                'FEDEX_2_DAY_FREIGHT' => 'FedEx 2 Day Freight',
                'FEDEX_3_DAY_FREIGHT' => 'FedEx 3 Day Freight',
                // International
                'INTERNATIONAL_PRIORITY' => 'FedEx International Priority',
                'INTERNATIONAL_ECONOMY' => 'FedEx International Economy',
                'INTERNATIONAL_FIRST' => 'FedEx International First',
                'INTERNATIONAL_PRIORITY_FREIGHT' => 'FedEx International Priority Freight',
                'INTERNATIONAL_ECONOMY_FREIGHT' => 'FedEx International Economy Freight',
                'EUROPE_FIRST_INTERNATIONAL_PRIORITY' => 'FedEx Europe First International Priority',
                'FEDEX_INTERNATIONAL_GROUND' => 'FedEx International Ground',
            ];
        }

        if ($carrierSlug === 'ups') {
            return [
                // Domestic
                'GND' => 'UPS Ground',
                '2DA' => 'UPS 2nd Day Air',
                '2DM' => 'UPS 2nd Day Air A.M.',
                '3DS' => 'UPS 3 Day Select',
                'NDA' => 'UPS Next Day Air',
                'NDS' => 'UPS Next Day Air Saver',
                'NDM' => 'UPS Next Day Air Early',
                // Standard/International
                'STD' => 'UPS Standard',
                'WXS' => 'UPS Worldwide Express',
                'WXD' => 'UPS Worldwide Expedited',
                'WXSP' => 'UPS Worldwide Express Plus',
                'WSV' => 'UPS Worldwide Saver',
                'SP' => 'UPS SurePost',
            ];
        }

        return [];
    }

    /**
     * Get all service types grouped by carrier.
     *
     * @return array<string, array<string, string>>
     */
    public static function getAllServiceTypes(): array
    {
        return [
            'FedEx' => self::getServiceTypesForCarrier('fedex'),
            'UPS' => self::getServiceTypesForCarrier('ups'),
        ];
    }

    // Relationships

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCarrier($query, int $carrierId)
    {
        return $query->where('carrier_id', $carrierId);
    }
}
