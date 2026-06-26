<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per carrier × year holding distinct-shipment denominators (total and
 * auxiliary-fee ships), which can't be derived additively from the per-category
 * charge rollup.
 */
class CarrierShipRollup extends Model
{
    protected $table = 'carrier_ship_rollup';

    protected $fillable = [
        'carrier_id',
        'year',
        'total_ships',
        'aux_ships',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'total_ships' => 'integer',
            'aux_ships' => 'integer',
        ];
    }
}
