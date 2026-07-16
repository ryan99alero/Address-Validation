<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A carrier billing account (ours or a customer's), owned by an AccountOwner. Ship-via codes
 * reference it instead of carrying a free-text account number, and BestWay derives the payer
 * from account_owner_id.
 */
class CarrierAccount extends Model
{
    protected $fillable = [
        'account_owner_id',
        'carrier_id',
        'account_number',
        'nickname',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AccountOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(AccountOwner::class, 'account_owner_id');
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return HasMany<ShipViaCode, $this>
     */
    public function shipViaCodes(): HasMany
    {
        return $this->hasMany(ShipViaCode::class, 'carrier_account_id');
    }

    /**
     * Normalize the account number so the same account can't drift into two rows.
     */
    protected function setAccountNumberAttribute(?string $value): void
    {
        $this->attributes['account_number'] = $value !== null ? strtoupper(trim($value)) : null;
    }
}
