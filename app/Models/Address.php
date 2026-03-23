<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * Use guarded instead of fillable to allow dynamic fields.
     *
     * @var array<string>
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_ship_date' => 'date',
            'required_on_site_date' => 'date',
            'ship_via_date' => 'date',
            'fastest_date' => 'date',
            'ground_date' => 'date',
            'estimated_delivery_date' => 'date',
            'suggested_delivery_date' => 'date',
            'ship_via_meets_deadline' => 'boolean',
            'can_meet_required_date' => 'boolean',
            'bestway_optimized' => 'boolean',
            'is_residential' => 'boolean',
            'confidence_score' => 'decimal:2',
            'distance_miles' => 'decimal:2',
            'validated_at' => 'datetime',
            'extra_data' => 'array',
        ];
    }

    /**
     * Standard system fields for import mapping.
     *
     * @return array<string, string>
     */
    public static function getSystemFields(): array
    {
        return [
            'external_reference' => 'External Reference/ID',
            'input_name' => 'Recipient Name',
            'input_company' => 'Company Name',
            'input_address_1' => 'Address Line 1',
            'input_address_2' => 'Address Line 2',
            'input_city' => 'City',
            'input_state' => 'State/Province',
            'input_postal' => 'Postal/ZIP Code',
            'input_country' => 'Country Code',
            'ship_via_code' => 'Ship Via Code',
            'requested_ship_date' => 'Requested Ship Date',
            'required_on_site_date' => 'Required On-Site Date',
        ];
    }

    /**
     * Legacy field name mapping for backward compatibility.
     *
     * @return array<string, string>
     */
    public static function getLegacyFieldMap(): array
    {
        return [
            'name' => 'input_name',
            'company' => 'input_company',
            'address_line_1' => 'input_address_1',
            'address_line_2' => 'input_address_2',
            'city' => 'input_city',
            'state' => 'input_state',
            'postal_code' => 'input_postal',
            'country_code' => 'input_country',
        ];
    }

    /**
     * Get the formatted original (input) address.
     */
    public function getFormattedInputAddressAttribute(): string
    {
        $parts = array_filter([
            $this->input_address_1,
            $this->input_address_2,
            $this->input_city,
            $this->input_state,
            $this->input_postal,
            $this->input_country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get the formatted validated (output) address.
     */
    public function getFormattedOutputAddressAttribute(): string
    {
        $parts = array_filter([
            $this->output_address_1,
            $this->output_address_2,
            $this->output_city,
            $this->output_state,
            $this->output_postal,
            $this->output_country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Legacy accessor for backward compatibility.
     */
    public function getFormattedAddressAttribute(): string
    {
        return $this->formatted_input_address;
    }

    /**
     * Check if address has been validated.
     */
    public function isValidated(): bool
    {
        return $this->validation_status !== 'pending' && $this->validated_at !== null;
    }

    /**
     * Check if address has corrections (output differs from input).
     */
    public function hasCorrections(): bool
    {
        return $this->output_address_1 !== null
            && ($this->output_address_1 !== $this->input_address_1
                || $this->output_city !== $this->input_city
                || $this->output_state !== $this->input_state
                || $this->output_postal !== $this->input_postal);
    }

    /**
     * Get full postal code with extension (ZIP+4 format).
     */
    public function getFullPostalCode(): ?string
    {
        if (! $this->output_postal) {
            return null;
        }

        if ($this->output_postal_ext) {
            return $this->output_postal.'-'.$this->output_postal_ext;
        }

        return $this->output_postal;
    }

    /**
     * Get an extra field value by key.
     */
    public function getExtraField(string $key): ?string
    {
        $data = $this->extra_data ?? [];

        return $data[$key] ?? null;
    }

    /**
     * Set an extra field value.
     */
    public function setExtraField(string $key, ?string $value): void
    {
        $data = $this->extra_data ?? [];
        if ($value === null || $value === '') {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }
        $this->extra_data = $data;
    }

    /**
     * Get all extra field values.
     *
     * @return array<string, string>
     */
    public function getAllExtraFields(): array
    {
        return $this->extra_data ?? [];
    }

    /**
     * Set multiple extra fields at once.
     *
     * @param  array<string, string|null>  $fields
     */
    public function setExtraFields(array $fields): void
    {
        $data = $this->extra_data ?? [];
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        $this->extra_data = ! empty($data) ? $data : null;
    }

    /**
     * Apply validation result directly to address.
     *
     * @param  array<string, mixed>  $result
     */
    public function applyValidationResult(array $result, int $carrierId): void
    {
        $this->update([
            'output_address_1' => $result['corrected_address_line_1'] ?? $this->input_address_1,
            'output_address_2' => $result['corrected_address_line_2'] ?? $this->input_address_2,
            'output_city' => $result['corrected_city'] ?? $this->input_city,
            'output_state' => $result['corrected_state'] ?? $this->input_state,
            'output_postal' => $result['corrected_postal_code'] ?? $this->input_postal,
            'output_postal_ext' => $result['corrected_postal_code_ext'] ?? null,
            'output_country' => $result['corrected_country_code'] ?? $this->input_country,
            'validation_status' => $result['validation_status'] ?? 'valid',
            'is_residential' => $result['is_residential'] ?? null,
            'classification' => $result['classification'] ?? null,
            'confidence_score' => $result['confidence_score'] ?? null,
            'validated_by_carrier_id' => $carrierId,
            'validated_at' => now(),
        ]);
    }

    /**
     * Apply transit time results directly to address.
     *
     * @param  array<string, mixed>  $transitData
     */
    public function applyTransitResults(array $transitData): void
    {
        $this->update([
            'ship_via_service' => $transitData['ship_via_service'] ?? null,
            'ship_via_days' => $transitData['ship_via_days'] ?? null,
            'ship_via_date' => $transitData['ship_via_date'] ?? null,
            'ship_via_meets_deadline' => $transitData['ship_via_meets_deadline'] ?? null,
            'fastest_service' => $transitData['fastest_service'] ?? null,
            'fastest_days' => $transitData['fastest_days'] ?? null,
            'fastest_date' => $transitData['fastest_date'] ?? null,
            'ground_service' => $transitData['ground_service'] ?? null,
            'ground_days' => $transitData['ground_days'] ?? null,
            'ground_date' => $transitData['ground_date'] ?? null,
            'distance_miles' => $transitData['distance_miles'] ?? null,
        ]);
    }

    // Relationships

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validatedByCarrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'validated_by_carrier_id');
    }

    public function shipViaCodeRecord(): BelongsTo
    {
        return $this->belongsTo(ShipViaCode::class, 'ship_via_code_id');
    }

    /**
     * Transit times (detailed per-service data).
     * Note: Summary data is denormalized on this model.
     */
    public function transitTimes(): HasMany
    {
        return $this->hasMany(TransitTime::class);
    }

    // Scopes

    public function scopeValidated($query)
    {
        return $query->whereNotNull('validated_at');
    }

    public function scopeNotValidated($query)
    {
        return $query->whereNull('validated_at');
    }

    public function scopePending($query)
    {
        return $query->where('validation_status', 'pending');
    }

    public function scopeValid($query)
    {
        return $query->where('validation_status', 'valid');
    }

    public function scopeInvalid($query)
    {
        return $query->where('validation_status', 'invalid');
    }

    public function scopeAmbiguous($query)
    {
        return $query->where('validation_status', 'ambiguous');
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeForBatch($query, int $batchId)
    {
        return $query->where('import_batch_id', $batchId);
    }
}
