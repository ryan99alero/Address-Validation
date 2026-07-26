<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChargeCategory extends Model
{
    protected $fillable = [
        'name',
        'abbreviation',
        'pace_cost_center',
        'parent_id',
        'sort_order',
        'is_active',
        'is_system',
    ];

    /**
     * Short code for compact display, falling back to the full name.
     */
    public function getAbbrAttribute(): string
    {
        return $this->abbreviation ?: $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ChargeCodeMapping::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(CarrierCharge::class);
    }

    public function chargeTypes(): HasMany
    {
        return $this->hasMany(CarrierChargeType::class);
    }
}
