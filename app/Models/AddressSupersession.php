<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressSupersession extends Model
{
    public const TRIGGER_RECORRECTION = 'recorrection';

    public const TRIGGER_VARIANT_CONFLICT = 'variant_conflict';

    public const TRIGGER_REVERIFY_DRIFT = 'reverify_drift';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_BACKFILL = 'backfill';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED_GARBAGE = 'rejected_garbage';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'old_corrected_address_id',
        'new_corrected_address_id',
        'old_snapshot',
        'new_snapshot',
        'carrier_id',
        'carrier_invoice_line_id',
        'trigger',
        'status',
        'guard_result',
        'detected_at',
        'applied_at',
        'applied_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_snapshot' => 'array',
            'new_snapshot' => 'array',
            'guard_result' => 'array',
            'detected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function oldAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class, 'old_corrected_address_id');
    }

    public function newAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class, 'new_corrected_address_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoiceLine::class, 'carrier_invoice_line_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
