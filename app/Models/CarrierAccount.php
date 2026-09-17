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

    /**
     * Resolve the full account number from what an invoice printed. FedEx PDFs mask all but the last
     * digits (e.g. "XXXX-X560-4"), so a masked label is matched to a known account for the carrier by
     * its visible trailing digits. A fully-printed number (no mask) is trusted as its own digits.
     * Returns null when a masked label matches zero or more than one known account (ambiguous —
     * never guess which account to bill).
     */
    public static function resolveInvoiceAccount(int $carrierId, ?string $printed): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $printed) ?? '';
        if ($digits === '') {
            return null;
        }

        // A fully-printed account (no mask characters) is used verbatim.
        if (stripos((string) $printed, 'x') === false) {
            return $digits;
        }

        // Masked: match known accounts whose digits END WITH the visible suffix; only accept a unique hit.
        $matches = static::query()
            ->where('carrier_id', $carrierId)
            ->pluck('account_number')
            ->map(fn (?string $n): string => preg_replace('/\D/', '', (string) $n) ?? '')
            ->filter(fn (string $n): bool => $n !== '' && str_ends_with($n, $digits))
            ->unique()
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
