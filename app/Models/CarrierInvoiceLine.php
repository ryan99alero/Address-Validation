<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierInvoiceLine extends Model
{
    protected $fillable = [
        'carrier_invoice_id',
        'tracking_number',
        'ship_date',
        'delivery_date',
        'original_name',
        'original_company',
        'original_address_1',
        'original_address_2',
        'original_address_3',
        'original_city',
        'original_state',
        'original_postal',
        'original_country',
        'corrected_address_1',
        'corrected_address_2',
        'corrected_address_3',
        'corrected_city',
        'corrected_state',
        'corrected_postal',
        'corrected_country',
        'charge_code',
        'charge_description',
        'charge_amount',
        'corrected_address_id',
        'shipping_lookup_status',
        'shipping_lookup_at',
        'billed_to_pace',
        'billed_at',
        'pace_job_number',
        'pace_customer_id',
    ];

    public const LOOKUP_STATUS_FOUND = 'found';

    public const LOOKUP_STATUS_NOT_FOUND = 'not_found';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ship_date' => 'date',
            'delivery_date' => 'date',
            'charge_amount' => 'decimal:2',
            'billed_to_pace' => 'boolean',
            'billed_at' => 'datetime',
            'shipping_lookup_at' => 'datetime',
        ];
    }

    // Relationships

    public function carrierInvoice(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoice::class);
    }

    public function correctedAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class);
    }

    // Scopes

    public function scopeUnbilled($query)
    {
        return $query->where('billed_to_pace', false);
    }

    public function scopeBilled($query)
    {
        return $query->where('billed_to_pace', true);
    }

    public function scopeWithCorrections($query)
    {
        return $query->whereNotNull('corrected_address_1');
    }

    public function scopeNeedsShippingLookup($query)
    {
        return $query->whereNull('original_address_1')
            ->whereNull('shipping_lookup_status')
            ->whereNotNull('tracking_number');
    }

    public function scopeShippingLookupFound($query)
    {
        return $query->where('shipping_lookup_status', self::LOOKUP_STATUS_FOUND);
    }

    public function scopeShippingLookupNotFound($query)
    {
        return $query->where('shipping_lookup_status', self::LOOKUP_STATUS_NOT_FOUND);
    }

    // Methods

    /**
     * Check if this line has an address correction.
     */
    public function hasCorrection(): bool
    {
        return $this->corrected_address_1 !== null;
    }

    /**
     * Mark as billed to Pace.
     */
    public function markBilled(?string $jobNumber = null, ?string $customerId = null): void
    {
        $this->update([
            'billed_to_pace' => true,
            'billed_at' => now(),
            'pace_job_number' => $jobNumber,
            'pace_customer_id' => $customerId,
        ]);
    }

    /**
     * Link this line to the address correction cache.
     * Returns true if this created a NEW variant mapping (not a duplicate).
     */
    public function linkToCorrectionCache(): bool
    {
        if (! $this->hasCorrection()) {
            return false;
        }

        // Find or create the corrected address
        $result = CorrectedAddress::findOrCreateFromCorrection(
            $this->corrected_address_1,
            $this->corrected_address_2,
            $this->corrected_address_3,
            $this->corrected_city,
            $this->corrected_state,
            $this->corrected_postal,
            null,
            $this->corrected_country ?? 'us',
            $this->carrierInvoice?->carrier_id,
            null
        );

        $correctedAddress = $result['address'];
        $isNewVariant = false;

        // Create variant mapping for the original (bad) address
        if ($this->original_address_1 && $this->original_postal) {
            $variantResult = AddressVariant::createOrUpdateVariant(
                $correctedAddress->id,
                $this->original_address_1,
                $this->original_address_2,
                $this->original_city,
                $this->original_state,
                $this->original_postal,
                $this->original_country ?? 'us'
            );
            $isNewVariant = $variantResult['created'] ?? false;
        }

        // Link this invoice line to the corrected address
        $this->update(['corrected_address_id' => $correctedAddress->id]);

        return $isNewVariant;
    }

    // Accessors

    public function getOriginalFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->original_address_1,
            $this->original_address_2,
            $this->original_city,
            $this->original_state,
            $this->original_postal,
        ]);

        return implode(', ', $parts);
    }

    public function getCorrectedFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->corrected_address_1,
            $this->corrected_address_2,
            $this->corrected_city,
            $this->corrected_state,
            $this->corrected_postal,
        ]);

        return implode(', ', $parts);
    }
}
