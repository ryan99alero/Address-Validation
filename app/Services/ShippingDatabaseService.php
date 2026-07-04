<?php

namespace App\Services;

use App\Models\ShippingDatabaseSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class ShippingDatabaseService
{
    protected string $connection = 'shipping';

    protected string $table = 'xCarrierShipping';

    protected string $trackingColumn = 'trackingno';

    public function __construct()
    {
        $this->applyStoredSettings();
    }

    /**
     * Override the `shipping` connection config from the GUI settings row (if present +
     * enabled), so the connection is DB-configured rather than .env-only. Falls back to
     * the config/database.php (env) definition when no settings row exists.
     */
    protected function applyStoredSettings(): void
    {
        $setting = ShippingDatabaseSetting::current();
        if (! $setting) {
            return;
        }

        $this->table = $setting->table_name ?: $this->table;
        $this->trackingColumn = $setting->tracking_column ?: $this->trackingColumn;

        config(['database.connections.'.$this->connection => [
            'driver' => $setting->driver ?: 'sqlsrv',
            'host' => $setting->host,
            'port' => $setting->port ?: '1433',
            'database' => $setting->database,
            'username' => (string) $setting->username,
            'password' => (string) $setting->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $setting->encrypt ? 'yes' : 'no',
            'trust_server_certificate' => $setting->trust_server_certificate ? 'true' : 'false',
        ]]);

        DB::purge($this->connection);
    }

    /**
     * Look up a single shipment by tracking number.
     *
     * @return array{company: ?string, contact: ?string, add1: ?string, add2: ?string, city: ?string, state: ?string, zipcode: ?string, country: ?string}|null
     */
    public function lookupByTrackingNumber(string $trackingNumber): ?array
    {
        try {
            $result = DB::connection($this->connection)
                ->table($this->table)
                ->select(['company', 'contact', 'add1', 'add2', 'city', 'state', 'zipcode', 'country'])
                ->where($this->trackingColumn, $trackingNumber)
                ->first();

            return $result ? (array) $result : null;
        } catch (\Exception $e) {
            Log::warning('Shipping DB lookup failed', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Batch lookup multiple shipments by tracking numbers. Returns array keyed by tracking.
     *
     * @param  array<string>  $trackingNumbers
     * @return array<string, array{company: ?string, contact: ?string, add1: ?string, add2: ?string, city: ?string, state: ?string, zipcode: ?string, country: ?string}>
     */
    public function lookupBatch(array $trackingNumbers): array
    {
        if (empty($trackingNumbers)) {
            return [];
        }

        try {
            $results = DB::connection($this->connection)
                ->table($this->table)
                ->select([$this->trackingColumn.' as trackingno', 'company', 'contact', 'add1', 'add2', 'city', 'state', 'zipcode', 'country'])
                ->whereIn($this->trackingColumn, $trackingNumbers)
                ->get();

            $mapped = [];
            foreach ($results as $row) {
                $mapped[$row->trackingno] = [
                    'company' => $row->company,
                    'contact' => $row->contact,
                    'add1' => $row->add1,
                    'add2' => $row->add2,
                    'city' => $row->city,
                    'state' => $row->state,
                    'zipcode' => $row->zipcode,
                    'country' => $row->country,
                ];
            }

            return $mapped;
        } catch (\Exception $e) {
            Log::error('Shipping DB batch lookup failed', [
                'count' => count($trackingNumbers),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Test the connection, returning a human-readable result for the settings UI. Detects
     * the common "PHP sqlsrv driver not installed" case distinctly from a connect failure.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnectionDetailed(): array
    {
        $driver = config('database.connections.'.$this->connection.'.driver', 'sqlsrv');
        if (! in_array($driver, PDO::getAvailableDrivers(), true)) {
            return ['ok' => false, 'message' => "PHP driver '{$driver}' is not installed on this server (install php-sqlsrv / pdo_sqlsrv)."];
        }

        try {
            DB::connection($this->connection)->getPdo();
            $count = DB::connection($this->connection)->table($this->table)->limit(1)->count();

            return ['ok' => true, 'message' => "Connected. Table '{$this->table}' is reachable."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        }
    }

    /**
     * Simple boolean test (kept for the shipping:test command).
     */
    public function testConnection(): bool
    {
        return $this->testConnectionDetailed()['ok'];
    }

    /**
     * Whether the shipping database is configured, enabled, and the PHP driver is present.
     */
    public function isAvailable(): bool
    {
        $driver = config('database.connections.'.$this->connection.'.driver', 'sqlsrv');
        if (! in_array($driver, PDO::getAvailableDrivers(), true)) {
            return false;
        }

        $setting = ShippingDatabaseSetting::current();
        if ($setting) {
            return $setting->enabled && ! empty($setting->host);
        }

        // No settings row — fall back to the env-based config.
        $host = config('database.connections.'.$this->connection.'.host');

        return ! empty($host) && $host !== 'localhost';
    }
}
