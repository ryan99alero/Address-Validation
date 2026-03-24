<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrectedAddress extends Model
{
    protected $fillable = [
        'address_1',
        'address_2',
        'address_3',
        'city',
        'state',
        'postal',
        'postal_ext',
        'country',
        'address_hash',
        'first_carrier_id',
        'is_residential',
        'usage_count',
        'variant_count',
        'first_seen_at',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_residential' => 'boolean',
            'usage_count' => 'integer',
            'variant_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    // Relationships

    public function firstCarrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'first_carrier_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AddressVariant::class);
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(CarrierInvoiceLine::class);
    }

    // Static Methods

    /**
     * Normalize an address component to lowercase, trimmed, standardized.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = mb_strtolower(trim($value));

        // Standardize common abbreviations
        $replacements = [
            ' street' => ' st',
            ' avenue' => ' ave',
            ' boulevard' => ' blvd',
            ' drive' => ' dr',
            ' lane' => ' ln',
            ' road' => ' rd',
            ' court' => ' ct',
            ' place' => ' pl',
            ' circle' => ' cir',
            ' highway' => ' hwy',
            ' parkway' => ' pkwy',
            ' suite' => ' ste',
            ' apartment' => ' apt',
            ' building' => ' bldg',
            ' floor' => ' fl',
            ' north' => ' n',
            ' south' => ' s',
            ' east' => ' e',
            ' west' => ' w',
            ' northeast' => ' ne',
            ' northwest' => ' nw',
            ' southeast' => ' se',
            ' southwest' => ' sw',
        ];

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Remove extra whitespace
        $value = preg_replace('/\s+/', ' ', $value);

        // Remove common punctuation that doesn't affect matching
        $value = str_replace(['.', ',', '#'], '', $value);

        return trim($value);
    }

    /**
     * Normalize a postal code to standard format.
     * Handles malformed data like "67215120720" by extracting just the ZIP.
     */
    public static function normalizePostal(?string $postal): string
    {
        if ($postal === null || $postal === '') {
            return '';
        }

        $postal = trim($postal);

        // Remove any non-alphanumeric characters except hyphen
        $postal = preg_replace('/[^a-zA-Z0-9\-]/', '', $postal);

        // For US ZIP codes (all digits)
        if (preg_match('/^(\d{5})(\d{4})?/', $postal, $matches)) {
            // Standard 5-digit ZIP, optionally with 4-digit extension
            return $matches[1].(isset($matches[2]) ? '-'.$matches[2] : '');
        }

        // For Canadian postal codes (A1A 1A1 format)
        if (preg_match('/^([A-Za-z]\d[A-Za-z])\s*(\d[A-Za-z]\d)?/', $postal, $matches)) {
            return strtoupper($matches[1].(isset($matches[2]) ? ' '.$matches[2] : ''));
        }

        // Truncate if still too long (max 10 chars for safety)
        if (strlen($postal) > 10) {
            $postal = substr($postal, 0, 10);
        }

        return strtolower($postal);
    }

    /**
     * Compute hash for a corrected address.
     */
    public static function computeHash(
        string $address1,
        ?string $city,
        ?string $state,
        ?string $postal,
        ?string $country = 'us'
    ): string {
        $normalized = implode('|', [
            self::normalize($address1),
            self::normalize($city),
            self::normalize($state),
            self::normalizePostal($postal),
            self::normalize($country ?? 'us'),
        ]);

        return hash('sha256', $normalized);
    }

    /**
     * Find or create a corrected address record.
     *
     * @return array{address: CorrectedAddress, created: bool}
     */
    public static function findOrCreateFromCorrection(
        string $address1,
        ?string $address2,
        ?string $address3,
        string $city,
        string $state,
        string $postal,
        ?string $postalExt = null,
        string $country = 'us',
        ?int $carrierId = null,
        ?bool $isResidential = null
    ): array {
        $hash = self::computeHash($address1, $city, $state, $postal, $country);

        $existing = self::where('address_hash', $hash)->first();

        if ($existing) {
            $existing->increment('usage_count');
            $existing->update(['last_used_at' => now()]);

            return ['address' => $existing, 'created' => false];
        }

        $address = self::create([
            'address_1' => self::normalize($address1),
            'address_2' => $address2 ? self::normalize($address2) : null,
            'address_3' => $address3 ? self::normalize($address3) : null,
            'city' => self::normalize($city),
            'state' => self::normalize($state),
            'postal' => self::normalizePostal($postal),
            'postal_ext' => $postalExt ? self::normalizePostal($postalExt) : null,
            'country' => self::normalize($country),
            'address_hash' => $hash,
            'first_carrier_id' => $carrierId,
            'is_residential' => $isResidential,
            'usage_count' => 1,
            'variant_count' => 0,
            'first_seen_at' => now(),
            'last_used_at' => now(),
        ]);

        return ['address' => $address, 'created' => true];
    }

    // Accessors

    /**
     * Get the full address as a single line.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_1,
            $this->address_2,
            $this->address_3,
            $this->city,
            $this->state,
            $this->postal.($this->postal_ext ? '-'.$this->postal_ext : ''),
        ]);

        return implode(', ', $parts);
    }
}
