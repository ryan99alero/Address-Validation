<?php

namespace App\Models;

use Database\Factories\AddressCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressCorrection extends Model
{
    /** @use HasFactory<AddressCorrectionFactory> */
    use HasFactory;

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const CLASSIFICATION_RESIDENTIAL = 'residential';

    public const CLASSIFICATION_COMMERCIAL = 'commercial';

    public const CLASSIFICATION_MIXED = 'mixed';

    public const CLASSIFICATION_UNKNOWN = 'unknown';

    protected $fillable = [
        'address_id',
        'carrier_id',
        'validation_status',
        'corrected_address_line_1',
        'corrected_address_line_2',
        'corrected_city',
        'corrected_state',
        'corrected_postal_code',
        'corrected_postal_code_ext',
        'corrected_country_code',
        'is_residential',
        'classification',
        'confidence_score',
        'candidates_count',
        'raw_response',
        'validated_at',
        // Extra fields for custom data pass-through
        'extra_1', 'extra_2', 'extra_3', 'extra_4', 'extra_5',
        'extra_6', 'extra_7', 'extra_8', 'extra_9', 'extra_10',
        'extra_11', 'extra_12', 'extra_13', 'extra_14', 'extra_15',
        'extra_16', 'extra_17', 'extra_18', 'extra_19', 'extra_20',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_residential' => 'boolean',
            'confidence_score' => 'decimal:2',
            'candidates_count' => 'integer',
            'raw_response' => 'array',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * Check if the address was validated successfully.
     */
    public function isValid(): bool
    {
        return $this->validation_status === self::STATUS_VALID;
    }

    /**
     * Check if the address is invalid.
     */
    public function isInvalid(): bool
    {
        return $this->validation_status === self::STATUS_INVALID;
    }

    /**
     * Check if the address is ambiguous (multiple candidates).
     */
    public function isAmbiguous(): bool
    {
        return $this->validation_status === self::STATUS_AMBIGUOUS;
    }

    /**
     * Get the formatted corrected address.
     */
    public function getFormattedCorrectedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->corrected_address_line_1,
            $this->corrected_address_line_2,
            $this->corrected_city,
            $this->corrected_state,
            $this->getFullPostalCode(),
            $this->corrected_country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get full postal code with extension if available.
     */
    public function getFullPostalCode(): string
    {
        if ($this->corrected_postal_code_ext) {
            return $this->corrected_postal_code.'-'.$this->corrected_postal_code_ext;
        }

        return $this->corrected_postal_code ?? '';
    }

    /**
     * Check if there were any changes from original address.
     */
    public function hasAddressChanges(): bool
    {
        $original = $this->address;

        return $this->corrected_address_line_1 !== $original->address_line_1
            || $this->corrected_city !== $original->city
            || $this->corrected_state !== $original->state
            || $this->corrected_postal_code !== $original->postal_code;
    }

    // Relationships

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    // Scopes

    public function scopeValid($query)
    {
        return $query->where('validation_status', self::STATUS_VALID);
    }

    public function scopeInvalid($query)
    {
        return $query->where('validation_status', self::STATUS_INVALID);
    }

    public function scopeAmbiguous($query)
    {
        return $query->where('validation_status', self::STATUS_AMBIGUOUS);
    }

    public function scopeByCarrier($query, int $carrierId)
    {
        return $query->where('carrier_id', $carrierId);
    }

    /**
     * Get all candidate addresses from the raw API response.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllCandidates(): array
    {
        $rawResponse = $this->raw_response ?? [];
        $carrierSlug = $this->carrier?->slug;

        return match ($carrierSlug) {
            'smarty' => $this->parseSmartysCandidates($rawResponse),
            'ups' => $this->parseUpsCandidates($rawResponse),
            'fedex' => $this->parseFedExCandidates($rawResponse),
            default => [],
        };
    }

    /**
     * Parse Smarty API candidates.
     *
     * @param  array<int, array<string, mixed>>  $rawResponse
     * @return array<int, array<string, mixed>>
     */
    protected function parseSmartysCandidates(array $rawResponse): array
    {
        $candidates = [];

        foreach ($rawResponse as $index => $candidate) {
            $components = $candidate['components'] ?? [];
            $metadata = $candidate['metadata'] ?? [];
            $analysis = $candidate['analysis'] ?? [];

            $dpvMatchCode = $analysis['dpv_match_code'] ?? null;
            $confidence = match ($dpvMatchCode) {
                'Y' => 1.0,
                'S' => 0.8,
                'D' => 0.7,
                'N' => 0.3,
                default => 0.5,
            };

            $candidates[] = [
                'address_line_1' => $candidate['delivery_line_1'] ?? null,
                'address_line_2' => $candidate['delivery_line_2'] ?? null,
                'city' => $components['city_name'] ?? null,
                'state' => $components['state_abbreviation'] ?? null,
                'postal_code' => $components['zipcode'] ?? null,
                'postal_code_ext' => $components['plus4_code'] ?? null,
                'country_code' => 'US',
                'classification' => match ($metadata['rdi'] ?? null) {
                    'Residential' => 'residential',
                    'Commercial' => 'commercial',
                    default => 'unknown',
                },
                'confidence' => $confidence,
                'dpv_match_code' => $dpvMatchCode,
            ];
        }

        return $candidates;
    }

    /**
     * Parse UPS API candidates.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array<int, array<string, mixed>>
     */
    protected function parseUpsCandidates(array $rawResponse): array
    {
        $candidates = [];
        $xavResponse = $rawResponse['XAVResponse'] ?? [];
        $rawCandidates = $xavResponse['Candidate'] ?? [];

        // If single candidate, wrap in array
        if (isset($rawCandidates['AddressKeyFormat'])) {
            $rawCandidates = [$rawCandidates];
        }

        foreach ($rawCandidates as $candidate) {
            $addressKeyFormat = $candidate['AddressKeyFormat'] ?? [];
            $addressClassification = $candidate['AddressClassification'] ?? [];

            $addressLines = $addressKeyFormat['AddressLine'] ?? [];
            if (is_string($addressLines)) {
                $addressLines = [$addressLines];
            }

            $classificationCode = $addressClassification['Code'] ?? null;
            $confidence = isset($xavResponse['ValidAddressIndicator']) ? 1.0 : 0.6;

            $candidates[] = [
                'address_line_1' => $addressLines[0] ?? null,
                'address_line_2' => $addressLines[1] ?? null,
                'city' => $addressKeyFormat['PoliticalDivision2'] ?? null,
                'state' => $addressKeyFormat['PoliticalDivision1'] ?? null,
                'postal_code' => $addressKeyFormat['PostcodePrimaryLow'] ?? null,
                'postal_code_ext' => $addressKeyFormat['PostcodeExtendedLow'] ?? null,
                'country_code' => $addressKeyFormat['CountryCode'] ?? 'US',
                'classification' => match ($classificationCode) {
                    '1' => 'commercial',
                    '2' => 'residential',
                    default => 'unknown',
                },
                'confidence' => $confidence,
            ];
        }

        return $candidates;
    }

    /**
     * Parse FedEx API candidates.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array<int, array<string, mixed>>
     */
    protected function parseFedExCandidates(array $rawResponse): array
    {
        $candidates = [];
        $output = $rawResponse['output'] ?? [];
        $resolvedAddresses = $output['resolvedAddresses'] ?? [];

        foreach ($resolvedAddresses as $resolved) {
            $streetLines = $resolved['streetLinesToken'] ?? [];
            $state = $resolved['state'] ?? '';

            $postalCode = $resolved['postalCode'] ?? null;
            $postalCodeExt = null;
            if ($postalCode && str_contains($postalCode, '-')) {
                [$postalCode, $postalCodeExt] = explode('-', $postalCode, 2);
            }

            $confidence = match ($state) {
                'VALID' => 1.0,
                'MODIFIED' => 0.9,
                'AMBIGUOUS' => 0.5,
                default => 0.0,
            };

            $candidates[] = [
                'address_line_1' => $streetLines[0] ?? null,
                'address_line_2' => $streetLines[1] ?? null,
                'city' => $resolved['city'] ?? null,
                'state' => $resolved['stateOrProvinceCode'] ?? null,
                'postal_code' => $postalCode,
                'postal_code_ext' => $postalCodeExt,
                'country_code' => $resolved['countryCode'] ?? 'US',
                'classification' => match ($resolved['classification'] ?? null) {
                    'RESIDENTIAL' => 'residential',
                    'BUSINESS' => 'commercial',
                    'MIXED' => 'mixed',
                    default => 'unknown',
                },
                'confidence' => $confidence,
                'fedex_state' => $state,
            ];
        }

        return $candidates;
    }

    /**
     * Get formatted postal code for a candidate.
     */
    public static function formatPostalCode(?string $postalCode, ?string $ext): string
    {
        if (! $postalCode) {
            return '';
        }

        if ($ext) {
            return $postalCode.'-'.$ext;
        }

        return $postalCode;
    }
}
