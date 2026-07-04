<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A configured connection to an external SQL database, tagged with a purpose and a field
 * map so the same registry can serve many uses. Password is encrypted at rest.
 */
class SqlConnection extends Model
{
    /** Purpose: back-fill FedEx original recipient addresses by tracking number. */
    public const PURPOSE_SHIPPING_LOOKUP = 'shipping_address_lookup';

    protected $fillable = [
        'name',
        'purpose',
        'is_active',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'table_name',
        'field_map',
        'custom_query',
        'encrypt',
        'trust_server_certificate',
        'last_tested_at',
        'last_test_status',
    ];

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'encrypt' => 'boolean',
            'trust_server_certificate' => 'boolean',
            'password' => 'encrypted',
            'field_map' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Available purposes => human label. Extend as new SQL-backed uses are added.
     *
     * @return array<string, string>
     */
    public static function purposes(): array
    {
        return [
            self::PURPOSE_SHIPPING_LOOKUP => 'Shipping Address Lookup (FedEx original address)',
        ];
    }

    /**
     * The logical fields the shipping lookup needs, in "logical => default source column"
     * form — the editable field map. Keys are stable; values are the source column names.
     *
     * @return array<string, string>
     */
    public static function shippingFieldMapDefaults(): array
    {
        return [
            'tracking' => 'trackingno', // WHERE column (matched against carrier tracking numbers)
            'company' => 'company',
            'contact' => 'contact',
            'add1' => 'add1',
            'add2' => 'add2',
            'city' => 'city',
            'state' => 'state',
            'zipcode' => 'zipcode',
            'country' => 'country',
        ];
    }

    /**
     * The active connection for a purpose (first wins).
     */
    public static function active(string $purpose): ?self
    {
        return self::query()->where('purpose', $purpose)->where('is_active', true)->first();
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    /**
     * The effective field map, filling any unmapped logical field with its default column.
     *
     * @return array<string, string>
     */
    public function effectiveFieldMap(): array
    {
        $map = is_array($this->field_map) ? array_filter($this->field_map) : [];

        return array_merge(self::shippingFieldMapDefaults(), $map);
    }

    /**
     * A read-only preview of the SELECT that the shipping lookup will run, for display in the
     * form. The real query is parameterized; this is illustrative only.
     */
    public function previewQuery(): string
    {
        if (filled($this->custom_query)) {
            return trim((string) $this->custom_query);
        }

        $map = $this->effectiveFieldMap();
        $cols = implode(', ', array_values($map));
        $table = $this->table_name ?: 'xCarrierShipping';

        return "SELECT {$cols} FROM {$table} WHERE {$map['tracking']} IN (:trackingNumbers)";
    }
}
