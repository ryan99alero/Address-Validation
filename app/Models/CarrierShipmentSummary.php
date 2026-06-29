<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pre-aggregated per-shipment cost row (carrier × tracking × invoice-date),
 * built from carrier_charges by ShipmentSummaryService.
 */
class CarrierShipmentSummary extends Model
{
    protected $table = 'carrier_shipment_summary';

    protected $fillable = [
        'carrier_id',
        'tracking_number',
        'invoice_date',
        'base_amount',
        'fee_amount',
        'total_amount',
        'charge_count',
        'fee_abbrevs',
        'service',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'base_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'charge_count' => 'integer',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * The individual charge lines behind this shipment (same tracking #). Scope to
     * this carrier + invoice date in the consumer to match the summary's grain.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(CarrierCharge::class, 'tracking_number', 'tracking_number');
    }
}
