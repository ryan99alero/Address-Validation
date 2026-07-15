<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RETIRED. Superseded by CarrierShipment (carrier_shipments now carries the
 * per-shipment cost split for both carriers). This model is guarded to throw on
 * any read so a lingering consumer surfaces before the table is dropped. The
 * table is still written by ShipmentSummaryService (raw query builder, unaffected
 * by the guard) so data is preserved for a clean revert.
 */
class CarrierShipmentSummary extends Model
{
    protected $table = 'carrier_shipment_summary';

    protected static function booted(): void
    {
        static::addGlobalScope('retired', function (Builder $builder): void {
            throw new \RuntimeException(
                'CarrierShipmentSummary is retired — read carrier_shipments (CarrierShipment) instead. '
                .'This guard exists to surface any remaining consumer before the table is dropped.'
            );
        });
    }

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
