<?php

namespace App\Models;

use App\Enums\ChargeDisposition;
use Illuminate\Database\Eloquent\Model;

/**
 * Operator-editable catalog row for one charge driver (see App\Enums\ChargeDriver). Holds the
 * label/disposition/Pace mapping behind the "Carrier Chargeback Codes" settings page.
 */
class ChargeDriver extends Model
{
    protected $fillable = [
        'key',
        'label',
        'abbreviation',
        'description',
        'disposition',
        'color',
        'pace_activity_code',
        'fuel_cost_center',
        'push_to_pace',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disposition' => ChargeDisposition::class,
            'push_to_pace' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
