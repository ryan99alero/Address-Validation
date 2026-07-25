<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CarrierCharge extends Model
{
    protected $fillable = [
        'carrier_invoice_id',
        'carrier_shipment_id',
        'carrier_id',
        'invoice_date',
        'ship_date',
        'account_number',
        'tracking_number',
        'raw_charge_code',
        'raw_charge_description',
        'charge_category_id',
        'driver',
        'driver_source',
        'amount',
        'published',
        'incentive',
        'service',
        'zone',
        'weight',
        'source_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'ship_date' => 'date',
            'amount' => 'decimal:2',
            'published' => 'decimal:2',
            'incentive' => 'decimal:2',
            'weight' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoice::class, 'carrier_invoice_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChargeCategory::class, 'charge_category_id');
    }

    /**
     * The Pace carton mirror for this line's tracking number (not a FK — matched by tracking),
     * carrying the shipment's U_reference fields and ship cost.
     */
    public function cartonCost(): BelongsTo
    {
        return $this->belongsTo(CartonCost::class, 'tracking_number', 'tracking_number');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(CarrierShipment::class, 'carrier_shipment_id');
    }

    /**
     * Filter charges by billing type (third-party vs on-account), combining the
     * authoritative Pace flag with the base-charge heuristic, in that order:
     *   1. If the Pace carton mirror has an explicit is_third_party for the tracking,
     *      use it (works for UPS and FedEx — both key off tracking).
     *   2. Otherwise a tracking with NO Base Transportation charge is third-party
     *      (the carrier billed the transport elsewhere); WITH a base charge it's
     *      on-account.
     *
     * Only applies to charges that carry a tracking number; account-level fees
     * (null tracking) belong to neither bucket.
     */
    public function scopeThirdParty(Builder $query, bool $value = true): Builder
    {
        $baseCategoryId = static::baseTransportationCategoryId();

        $paceFlagIs = fn (int $flag) => function ($q) use ($flag) {
            $q->select(DB::raw(1))->from('carton_costs as cc')
                ->whereColumn('cc.tracking_number', 'carrier_charges.tracking_number')
                ->where('cc.is_third_party', $flag);
        };
        $paceHasFlag = function ($q) {
            $q->select(DB::raw(1))->from('carton_costs as cc')
                ->whereColumn('cc.tracking_number', 'carrier_charges.tracking_number')
                ->whereNotNull('cc.is_third_party');
        };
        $hasBaseCharge = function ($q) use ($baseCategoryId) {
            $q->select(DB::raw(1))->from('carrier_charges as bc')
                ->whereColumn('bc.tracking_number', 'carrier_charges.tracking_number')
                ->where('bc.charge_category_id', $baseCategoryId);
        };

        return $query
            ->whereNotNull('carrier_charges.tracking_number')
            ->where(function (Builder $w) use ($value, $paceFlagIs, $paceHasFlag, $hasBaseCharge) {
                if ($value) {
                    // Third-party: Pace says so, OR (Pace unknown AND no base charge).
                    $w->whereExists($paceFlagIs(1))
                        ->orWhere(fn (Builder $h) => $h->whereNotExists($paceHasFlag)->whereNotExists($hasBaseCharge));
                } else {
                    // On-account: Pace says so, OR (Pace unknown AND has a base charge).
                    $w->whereExists($paceFlagIs(0))
                        ->orWhere(fn (Builder $h) => $h->whereNotExists($paceHasFlag)->whereExists($hasBaseCharge));
                }
            });
    }

    /**
     * Charges billed to our own account (the complement of third-party over
     * tracking-bearing charges).
     */
    public function scopeOnAccount(Builder $query): Builder
    {
        return $query->thirdParty(false);
    }

    /**
     * Id of the "Base Transportation" charge category, used by the billing-type
     * heuristic (its presence on a tracking = on-account).
     */
    protected static function baseTransportationCategoryId(): ?int
    {
        return ChargeCategory::query()->where('name', 'Base Transportation')->value('id');
    }
}
