<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarrierShipment extends Model
{
    protected $fillable = [
        'carrier_invoice_id',
        'carrier_id',
        'tracking_number',
        'section',
        'service',
        'zip',
        'zone',
        'weight',
        'billed_weight',
        'ship_date',
        'customer_dims',
        'audited_dims',
        'customer_weight',
        'message_codes',
        'sender',
        'receiver',
        'third_party',
        'is_third_party',
        'printed_total',
        'base_amount',
        'fee_amount',
        'fee_abbrevs',
        'source_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ship_date' => 'date',
            'weight' => 'decimal:2',
            'billed_weight' => 'decimal:2',
            'customer_weight' => 'decimal:2',
            'printed_total' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'message_codes' => 'array',
            'is_third_party' => 'boolean',
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

    public function charges(): HasMany
    {
        return $this->hasMany(CarrierCharge::class, 'carrier_shipment_id');
    }

    /**
     * The charge lines behind this shipment, matched by tracking number within the
     * same invoice — works whether or not carrier_shipment_id was linked (FedEx
     * derived rows aren't linked by FK). Used by the Per-Shipment Costs view.
     */
    public function invoiceCharges(): HasMany
    {
        return $this->hasMany(CarrierCharge::class, 'tracking_number', 'tracking_number')
            ->where('carrier_charges.carrier_invoice_id', $this->carrier_invoice_id);
    }

    /**
     * A shipment where UPS audited the dimensions to something larger than the customer
     * entered — the DIM re-rate dispute signal.
     */
    public function wasDimensionAudited(): bool
    {
        return $this->audited_dims !== null;
    }
}
