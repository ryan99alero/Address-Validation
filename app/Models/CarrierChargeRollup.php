<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per carrier × fee-category × year, summarising carrier_charges so the
 * report pages never scan the raw 3.4M-row table.
 */
class CarrierChargeRollup extends Model
{
    protected $table = 'carrier_charge_rollup';

    protected $fillable = [
        'carrier_id',
        'charge_category_id',
        'is_third_party',
        'year',
        'charge_count',
        'total_amount',
        'distinct_ships',
    ];

    protected function casts(): array
    {
        return [
            'is_third_party' => 'boolean',
            'year' => 'integer',
            'charge_count' => 'integer',
            'total_amount' => 'decimal:2',
            'distinct_ships' => 'integer',
        ];
    }
}
