<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargeCodeMapping extends Model
{
    public const MATCH_CODE = 'code';

    public const MATCH_DESCRIPTION = 'description';

    protected $fillable = [
        'carrier_id',
        'match_type',
        'match_value',
        'charge_category_id',
        'priority',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
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
