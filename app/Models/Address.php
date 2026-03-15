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

    protected $fillable = [
        'external_reference',
        'name',
        'company',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'source',
        'source_row_number',
        'import_batch_id',
        'created_by',
        // Extra fields for custom data pass-through
        'extra_1', 'extra_2', 'extra_3', 'extra_4', 'extra_5',
        'extra_6', 'extra_7', 'extra_8', 'extra_9', 'extra_10',
        'extra_11', 'extra_12', 'extra_13', 'extra_14', 'extra_15',
        'extra_16', 'extra_17', 'extra_18', 'extra_19', 'extra_20',
    ];

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
