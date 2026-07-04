<?php

namespace App\Models;

use App\Observers\CarrierInvoiceLineObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CarrierInvoiceLineObserver::class])]
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
        'severity_score',
        'severity_category',
        'change_type',
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
            'severity_score' => 'integer',
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

        // Create variant mapping for the original (bad) address — but NOT when the
        // "original" is our own address. Carriers sometimes encode the shipper (RAND)
        // as the original recipient on returns/undeliverables; the invoice line keeps
        // that factual data, but teaching the validation cache to "correct" our own
        // address to a customer's would poison every future lookup of our address.
        if ($this->original_address_1 && $this->original_postal && ! $this->originalIsOwnAddress()) {
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

    /**
     * True when this line's ORIGINAL address is our own company origin address — used to
     * keep our address out of the validation cache as a "bad" variant. Matches on the
     * street "core" (house number + primary name, with directionals and suffixes stripped)
     * + 5-digit ZIP, so "2820 South Hoover" and "2820 S. Hoover Rd" resolve to the same
     * core "2820 HOOVER".
     */
    public function originalIsOwnAddress(): bool
    {
        $company = CompanySetting::instance();
        if (empty($company->address_line_1) || empty($company->postal_code)) {
            return false;
        }

        $zip5 = fn (?string $s): string => substr((string) preg_replace('/[^0-9]/', '', (string) $s), 0, 5);

        $own = self::streetCore($company->address_line_1);
        $line = self::streetCore($this->original_address_1);
        if ($own === '' || $line === '') {
            return false;
        }

        return $own === $line && $zip5($this->original_postal) === $zip5($company->postal_code);
    }

    /**
     * Reduce a street line to a comparable core: uppercase, drop punctuation, and remove
     * directional (N/S/E/W/NORTH/…) and street-suffix (RD/ROAD/ST/AVE/…) tokens, leaving
     * just the house number + primary name (e.g. "2820 S. Hoover Rd" -> "2820HOOVER").
     */
    public static function streetCore(?string $street): string
    {
        $noise = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW', 'NORTH', 'SOUTH', 'EAST', 'WEST',
            'RD', 'ROAD', 'ST', 'STREET', 'AVE', 'AVENUE', 'BLVD', 'BOULEVARD', 'DR', 'DRIVE',
            'LN', 'LANE', 'CT', 'COURT', 'PL', 'PLACE', 'CIR', 'CIRCLE', 'HWY', 'HIGHWAY',
            'PKWY', 'PARKWAY', 'WAY', 'TER', 'TERRACE', 'PLZ', 'PLAZA', 'SQ', 'LOOP', 'TRL', 'TRAIL'];

        $tokens = preg_split('/\s+/', trim(strtoupper((string) preg_replace('/[^A-Za-z0-9\s]/', ' ', (string) $street))));

        return implode('', array_filter($tokens, fn (string $t): bool => $t !== '' && ! in_array($t, $noise, true)));
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
