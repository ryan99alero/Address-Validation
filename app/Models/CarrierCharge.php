<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierCharge extends Model
{
    protected $fillable = [
        'carrier_invoice_id',
        'carrier_id',
        'invoice_date',
        'account_number',
        'tracking_number',
        'raw_charge_code',
        'raw_charge_description',
        'charge_category_id',
        'amount',
        'service',
        'zone',
        'weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'amount' => 'decimal:2',
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
}
