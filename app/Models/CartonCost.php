<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A local mirror of a Pace "Carton" — one physical package / tracking number, carrying the
 * cost Process Shipper recorded at ship time. This is the recoup baseline: the carrier's
 * invoiced total for the same tracking, minus this ship_cost, is what gets billed back to the
 * customer. Synced from the configured carton source by CartonCostSyncService.
 */
class CartonCost extends Model
{
    protected $fillable = [
        'tracking_number',
        'ship_cost',
        'ship_date',
        'pace_job_number',
        'pace_customer_id',
        'synced_at',
        'recouped_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ship_cost' => 'decimal:2',
            'ship_date' => 'date',
            'synced_at' => 'datetime',
            'recouped_at' => 'datetime',
        ];
    }

    /**
     * The carrier charges billed against this carton's tracking number.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(CarrierCharge::class, 'tracking_number', 'tracking_number');
    }
}
