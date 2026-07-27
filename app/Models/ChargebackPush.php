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

    public const STATUS_SKIPPED_JOB_CLOSED = 'skipped_job_closed'; // Job/adminStatus/@jobChargesOK = false (not billable)

    public const STATUS_SKIPPED_NO_JOBSHIPMENT = 'skipped_no_jobshipment';

    public const STATUS_SKIPPED_AMBIGUOUS = 'skipped_ambiguous_shipment';

    public const STATUS_SKIPPED_CREDIT = 'skipped_credit';

    public const STATUS_RECORDED = 'recorded';         // resolved + billable, but record-only mode: written, not pushed

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_QUARANTINED = 'quarantined';   // same shipment+invoice, different amount/category — needs a human

    public const STATUS_DISMISSED = 'dismissed';       // reviewer declined the quarantined candidate (soft, hash retained)

    public const REVERSAL_NEEDS = 'needs_reversal';    // a duplicate JobCost that must be backed out of Pace

    public const REVERSAL_PENDING = 'reverse_pending';

    public const REVERSAL_FAILED = 'reverse_failed';

    public const CONFLICT_AMOUNT = 'amount_changed';   // same shipment+category, a re-import changed the amount

    public const CONFLICT_CATEGORY = 'category_changed'; // same shipment+amount, a re-import recategorized it

    protected $fillable = [
        'txn_id', 'identity_version', 'dedupe_key', 'duplicate_of_id', 'conflict_with_id', 'conflict_reason',
        'carrier_charge_id', 'carrier_id', 'carrier_invoice_id', 'tracking_number', 'charge_category_id',
        'driver', 'amount', 'ship_date', 'pace_job', 'pace_job_part', 'pace_customer_id',
        'pace_customer_name', 'pace_csr_name', 'pace_salesperson_name', 'activity_code',
        'notes', 'pace_jobcost_id', 'response_snapshot', 'status', 'reversal_state', 'reviewed_by_id',
        'reviewed_at', 'review_note', 'attempts', 'last_error', 'pushed_at',
    ];

    /** @var array<int, string> */
    private static array $carrierCodeCache = [];

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
            'reviewed_at' => 'datetime',
            'response_snapshot' => 'array',
        ];
    }

    /**
     * The STABLE chargeback identity. Deterministic content hash of the charge's business meaning —
     * reproducible from the charge alone (so it survives a full DB rebuild) and, crucially, keyed on
     * INVOICE identity (number + date) rather than ship_date. ship_date is enriched data that flips
     * null->populated on re-import and used to fork one charge into two; invoice_number+date is stable
     * across CSV/PDF re-imports and correctly distinguishes a genuinely recycled tracking (it lands on
     * a different invoice). Carrier is its stable slug, category its cost-center activity code — never
     * raw DB ids, which a reseed would renumber.
     *
     * @param  array{carrier_id?:int, tracking_number?:?string, activity_code?:?string, amount?:float|string, invoice_number?:?string, invoice_date?:?string}  $c
     */
    public static function identity(array $c): string
    {
        $raw = implode('|', [
            'cbv1',
            self::carrierCode($c['carrier_id'] ?? null),
            strtolower(trim((string) ($c['tracking_number'] ?? ''))),
            trim((string) ($c['activity_code'] ?? '')),
            (int) round(((float) ($c['amount'] ?? 0)) * 100),
            trim((string) ($c['invoice_number'] ?? '')),
            trim((string) ($c['invoice_date'] ?? '')),
        ]);

        return 'CB1-'.substr(hash('sha256', $raw), 0, 48);
    }

    /**
     * The txn_id truncated to Pace's 50-character `ioID` limit. The full 52-char txn_id stays the
     * internal dedup/idempotency key (never sent verbatim to Pace, which 500s on >50 chars). Both
     * the JobCost we post and the recovery probe use this same truncation, so idempotency holds.
     */
    public static function paceIoId(string $txnId): string
    {
        return substr($txnId, 0, 50);
    }

    /**
     * Carrier slug (UPS/FEDEX), memoised — a business code, stable across a reference-data reseed
     * where the numeric id is not. Falls back to the id if the carrier can't be resolved.
     */
    private static function carrierCode(int|string|null $carrierId): string
    {
        if ($carrierId === null) {
            return '';
        }
        if (! array_key_exists($carrierId, self::$carrierCodeCache)) {
            self::$carrierCodeCache[$carrierId] = strtoupper((string) (Carrier::query()->whereKey($carrierId)->value('slug') ?? $carrierId));
        }

        return self::$carrierCodeCache[$carrierId];
    }

    /**
     * The natural identity for a charge — RETAINED for forensics only (no longer the mutex). Includes
     * ship_date, which is exactly why it fractured on re-import; see identity().
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

    /**
     * The Pace carton mirror for this row's tracking number (not a FK — matched by tracking),
     * carrying the shipment's U_reference fields.
     */
    public function cartonCost(): BelongsTo
    {
        return $this->belongsTo(CartonCost::class, 'tracking_number', 'tracking_number');
    }

    /** The canonical row this duplicate points at (set when a re-import forked a charge). */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /** The already-posted charge a quarantined row conflicts with (same shipment, changed amount/category). */
    public function conflictWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conflict_with_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
