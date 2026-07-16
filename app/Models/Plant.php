<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A shipping plant (physical site). Minimal lookup — `code` is the value ship_via_codes.plant_id
 * and import_batches.bestway_plant_id join on; the table exists to feed drift-proof dropdowns.
 */
class Plant extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Uppercase/trim the code so "Plant002" and "PLANT002" can't coexist.
     */
    protected function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value !== null ? strtoupper(trim($value)) : null;
    }

    /**
     * @return array<string, string> code => "CODE — Name" for select options
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (self $p): array => [$p->code => $p->name ? "{$p->code} — {$p->name}" : $p->code])
            ->all();
    }
}
