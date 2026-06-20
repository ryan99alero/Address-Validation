<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressCandidate extends Model
{
    public const SOURCE_INVOICE_DB = 'invoice_db';

    public const SOURCE_FEDEX_API = 'fedex_api';

    public const SOURCE_UPS_API = 'ups_api';

    public const SOURCE_USPS_API = 'usps_api';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'address_id',
        'source',
        'address_1',
        'address_2',
        'city',
        'state',
        'postal',
        'postal_ext',
        'country',
        'is_residential',
        'classification',
        'confidence_score',
        'carrier_id',
        'corrected_address_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_residential' => 'boolean',
            'confidence_score' => 'decimal:2',
        ];
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function correctedAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class);
    }

    /**
     * Make this candidate the address's final corrected address, then purge all
     * candidates (including this one) for that address.
     */
    public function choose(): void
    {
        $address = $this->address;

        $validationSource = match ($this->source) {
            self::SOURCE_INVOICE_DB => Address::SOURCE_LOCAL_CACHE,
            self::SOURCE_FEDEX_API => Address::SOURCE_FEDEX_API,
            self::SOURCE_UPS_API => Address::SOURCE_UPS_API,
            self::SOURCE_USPS_API => Address::SOURCE_USPS_API,
            default => Address::SOURCE_MANUAL,
        };

        $address->update([
            'output_address_1' => $this->address_1,
            'output_address_2' => $this->address_2,
            'output_city' => $this->city,
            'output_state' => $this->state,
            'output_postal' => $this->postal,
            'output_postal_ext' => $this->postal_ext,
            'output_country' => $this->country,
            'is_residential' => $this->is_residential,
            'classification' => $this->classification,
            'confidence_score' => $this->confidence_score,
            'validation_status' => Address::STATUS_VALID,
            'validation_source' => $validationSource,
            'validated_by_carrier_id' => $this->carrier_id ?? $address->validated_by_carrier_id,
            'validated_at' => now(),
        ]);

        $address->candidates()->delete();
    }

    /**
     * Human label for this candidate's source.
     */
    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            self::SOURCE_INVOICE_DB => 'Invoice DB',
            self::SOURCE_FEDEX_API => 'FedEx API',
            self::SOURCE_UPS_API => 'UPS API',
            self::SOURCE_USPS_API => 'USPS API',
            self::SOURCE_MANUAL => 'Manual',
            default => ucfirst((string) $this->source),
        };
    }

    /**
     * The candidate address as a single line, for display/comparison.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_1,
            $this->address_2,
            $this->city,
            $this->state,
            $this->postal.($this->postal_ext ? '-'.$this->postal_ext : ''),
        ]);

        return implode(', ', $parts);
    }
}
