<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressVerification extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_DRIFTED = 'drifted';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_API = 'api';

    public const SOURCE_INVOICE = 'invoice';

    protected $fillable = [
        'corrected_address_id',
        'carrier_id',
        'status',
        'verified_at',
        'checked_at',
        'source',
        'result_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
            'result_snapshot' => 'array',
        ];
    }

    public function correctedAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
