<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in the chargeback ledger — see the create migration. Status is the disposition of every
 * eligible dollar (Fable: every eligible charge has exactly one visible outcome).
 */
class ChargebackPush extends Model
{
    public const STATUS_PENDING = 'pending';           // claimed, create not yet confirmed

    public const STATUS_PUSHED = 'pushed';             // JobCost created + confirmed

    public const STATUS_UNVERIFIED = 'unverified';     // create sent, outcome unknown (timeout) — reconcile

    public const STATUS_FAILED = 'failed';             // gave up after retries (Putback_Failed)

    public const STATUS_SKIPPED_JOB_CLOSED = 'skipped_job_closed';

    public const STATUS_SKIPPED_NO_JOBSHIPMENT = 'skipped_no_jobshipment';

    public const STATUS_SKIPPED_AMBIGUOUS = 'skipped_ambiguous_shipment';

    public const STATUS_SKIPPED_CREDIT = 'skipped_credit';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'dedupe_key', 'carrier_charge_id', 'carrier_id', 'carrier_invoice_id', 'tracking_number',
        'charge_category_id', 'driver', 'amount', 'ship_date', 'pace_job', 'pace_job_part',
        'pace_customer_id', 'activity_code', 'notes', 'pace_jobcost_id', 'response_snapshot',
        'status', 'attempts', 'last_error', 'pushed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'ship_date' => 'date',
            'attempts' => 'integer',
            'pushed_at' => 'datetime',
            'response_snapshot' => 'array',
        ];
    }

    /**
     * The natural identity for a charge — survives charge delete/recreate on re-import. ship_date
     * falls back to a sentinel so null dates still dedupe (MySQL treats NULLs as distinct in a
     * unique index).
     */
    public static function dedupeKey(int $carrierId, ?string $tracking, ?int $categoryId, float|string $amount, ?string $shipDate): string
    {
        return implode('|', [
            $carrierId,
            trim((string) $tracking),
            $categoryId ?? '-',
            number_format((float) $amount, 2, '.', ''),
            $shipDate ?: '-',
        ]);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(CarrierCharge::class, 'carrier_charge_id');
    }
}
