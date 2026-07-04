<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Singleton settings row for the external shipping database (SQL Server) connection.
 * The password is encrypted at rest.
 */
class ShippingDatabaseSetting extends Model
{
    protected $fillable = [
        'enabled',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'table_name',
        'tracking_column',
        'encrypt',
        'trust_server_certificate',
        'last_tested_at',
        'last_test_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'encrypt' => 'boolean',
            'trust_server_certificate' => 'boolean',
            'password' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Singleton accessor — creates the row if absent.
     */
    public static function instance(): self
    {
        return self::first() ?? self::create([]);
    }

    /**
     * Safe accessor for use during connection setup: returns null (never throws) when the
     * table doesn't exist yet (e.g. before this migration runs).
     */
    public static function current(): ?self
    {
        try {
            return Schema::hasTable('shipping_database_settings') ? self::first() : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
