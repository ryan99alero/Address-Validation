<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * Use guarded instead of fillable to allow dynamic extra fields.
     * Only protect id and timestamps.
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
            'estimated_delivery_date' => 'date',
            'can_meet_required_date' => 'boolean',
        ];
    }

    /**
     * Standard system fields for mapping.
     *
     * @return array<string, string>
     */
    public static function getSystemFields(): array
    {
        return [
            'external_reference' => 'External Reference/ID',
            'name' => 'Recipient Name',
            'company' => 'Company Name',
            'address_line_1' => 'Address Line 1',
            'address_line_2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State/Province',
            'postal_code' => 'Postal/ZIP Code',
            'country_code' => 'Country Code',
            'ship_via_code' => 'Ship Via Code',
            'requested_ship_date' => 'Requested Ship Date',
            'required_on_site_date' => 'Required On-Site Date',
        ];
    }

    /**
     * Get the formatted full address.
     */
    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if address has been validated.
     */
    public function isValidated(): bool
    {
        return $this->corrections()->exists();
    }

    /**
     * Get the latest correction for this address.
     */
    public function getLatestCorrectionAttribute(): ?AddressCorrection
    {
        return $this->corrections()->latest('validated_at')->first();
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

    public function corrections(): HasMany
    {
        return $this->hasMany(AddressCorrection::class);
    }

    public function latestCorrection(): HasOne
    {
        return $this->hasOne(AddressCorrection::class)->latestOfMany('validated_at');
    }

    public function transitTimes(): HasMany
    {
        return $this->hasMany(TransitTime::class);
    }

    public function shipViaCodeRecord(): BelongsTo
    {
        return $this->belongsTo(ShipViaCode::class, 'ship_via_code_id');
    }

    /**
     * Get transit time for a specific service type.
     */
    public function getTransitTimeForService(string $serviceType): ?TransitTime
    {
        return $this->transitTimes->firstWhere('service_type', $serviceType);
    }

    // Scopes

    public function scopeValidated($query)
    {
        return $query->whereHas('corrections');
    }

    public function scopeNotValidated($query)
    {
        return $query->whereDoesntHave('corrections');
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }
}
