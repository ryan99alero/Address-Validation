<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The party a carrier account is billed to — us (`company`) or a client (`customer`).
 * BestWay pools carrier accounts by owner and never crosses owners.
 */
class AccountOwner extends Model
{
    public const TYPE_COMPANY = 'company';

    public const TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'name',
        'type',
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
     * @return HasMany<CarrierAccount, $this>
     */
    public function carrierAccounts(): HasMany
    {
        return $this->hasMany(CarrierAccount::class);
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_COMPANY => 'Company (us)',
            self::TYPE_CUSTOMER => 'Customer',
        ];
    }
}
