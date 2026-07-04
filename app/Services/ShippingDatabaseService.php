<?php

namespace App\Services;

use App\Models\SqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Looks up shipment addresses in the external SQL database configured as the active
 * "shipping address lookup" SQL connection (Integrations > SQL Connections). The source
 * table and column names come from that connection's field map, so the query adapts
 * without code changes.
 */
class ShippingDatabaseService
{
    protected string $connectionName = 'shipping';

    protected ?SqlConnection $connection;

    /** @var array<string, string> logical field => source column */
    protected array $fieldMap;

    protected string $table;

    public function __construct()
    {
        $this->connection = SqlConnection::active(SqlConnection::PURPOSE_SHIPPING_LOOKUP);
        $this->fieldMap = $this->connection ? $this->connection->effectiveFieldMap() : SqlConnection::shippingFieldMapDefaults();
        $this->table = $this->connection?->table_name ?: 'xCarrierShipping';

        $this->applyRuntimeConfig();
    }

    /**
     * Override the Laravel `shipping` connection from the stored SqlConnection (if any).
     */
    protected function applyRuntimeConfig(): void
    {
        if (! $this->connection) {
            return;
        }

        config(['database.connections.'.$this->connectionName => [
            'driver' => $this->connection->driver ?: 'sqlsrv',
            'host' => $this->connection->host,
            'port' => $this->connection->port ?: '1433',
            'database' => $this->connection->database,
            'username' => (string) $this->connection->username,
            'password' => (string) $this->connection->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $this->connection->encrypt ? 'yes' : 'no',
            'trust_server_certificate' => $this->connection->trust_server_certificate ? 'true' : 'false',
        ]]);

        DB::purge($this->connectionName);
    }

    /**
     * The output columns (everything in the map except the tracking key), as
     * "sourceColumn as logicalKey" so results are keyed by the logical names the
     * back-fill consumer expects (company, contact, add1, …).
     *
     * @return array<int, string>
     */
    protected function selectColumns(): array
    {
        $cols = [];
        foreach ($this->fieldMap as $logical => $source) {
            if ($logical === 'tracking') {
                continue;
            }
            $cols[] = "{$source} as {$logical}";
        }

        return $cols;
    }

    protected function trackingColumn(): string
    {
        return $this->fieldMap['tracking'] ?? 'trackingno';
    }

    /**
     * @return array{company: ?string, contact: ?string, add1: ?string, add2: ?string, city: ?string, state: ?string, zipcode: ?string, country: ?string}|null
     */
    public function lookupByTrackingNumber(string $trackingNumber): ?array
    {
        try {
            $result = DB::connection($this->connectionName)
                ->table($this->table)
                ->selectRaw(implode(', ', $this->selectColumns()))
                ->where($this->trackingColumn(), $trackingNumber)
                ->first();

            return $result ? (array) $result : null;
        } catch (\Exception $e) {
            Log::warning('Shipping DB lookup failed', ['tracking_number' => $trackingNumber, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string>  $trackingNumbers
     * @return array<string, array{company: ?string, contact: ?string, add1: ?string, add2: ?string, city: ?string, state: ?string, zipcode: ?string, country: ?string}>
     */
    public function lookupBatch(array $trackingNumbers): array
    {
        if (empty($trackingNumbers)) {
            return [];
        }

        try {
            $trackingCol = $this->trackingColumn();
            $select = array_merge(["{$trackingCol} as trackingno"], $this->selectColumns());

            $results = DB::connection($this->connectionName)
                ->table($this->table)
                ->selectRaw(implode(', ', $select))
                ->whereIn($trackingCol, $trackingNumbers)
                ->get();

            $mapped = [];
            foreach ($results as $row) {
                $row = (array) $row;
                $key = $row['trackingno'];
                unset($row['trackingno']);
                $mapped[$key] = $row;
            }

            return $mapped;
        } catch (\Exception $e) {
            Log::error('Shipping DB batch lookup failed', ['count' => count($trackingNumbers), 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Test a connection, returning a readable result. Optionally tests a specific
     * SqlConnection (used by the "Test" action before it's the active one).
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnectionDetailed(?SqlConnection $connection = null): array
    {
        $connection ??= $this->connection;
        if (! $connection) {
            return ['ok' => false, 'message' => 'No SQL connection configured for shipping lookup.'];
        }

        $driver = $connection->driver ?: 'sqlsrv';
        if (! in_array($driver, PDO::getAvailableDrivers(), true)) {
            return ['ok' => false, 'message' => "PHP driver '{$driver}' is not installed on this server (install php-sqlsrv / pdo_sqlsrv)."];
        }

        $name = 'sql_test_'.$connection->id;
        config(['database.connections.'.$name => [
            'driver' => $driver,
            'host' => $connection->host,
            'port' => $connection->port ?: '1433',
            'database' => $connection->database,
            'username' => (string) $connection->username,
            'password' => (string) $connection->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $connection->encrypt ? 'yes' : 'no',
            'trust_server_certificate' => $connection->trust_server_certificate ? 'true' : 'false',
        ]]);

        try {
            DB::connection($name)->getPdo();
            $table = $connection->table_name ?: $this->table;
            DB::connection($name)->table($table)->limit(1)->count();

            return ['ok' => true, 'message' => "Connected. Table '{$table}' is reachable."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        } finally {
            DB::purge($name);
        }
    }

    public function testConnection(): bool
    {
        return $this->testConnectionDetailed()['ok'];
    }

    /**
     * Whether an active shipping-lookup connection is configured and its PHP driver present.
     */
    public function isAvailable(): bool
    {
        if (! $this->connection) {
            return false;
        }

        $driver = $this->connection->driver ?: 'sqlsrv';

        return $this->connection->is_active
            && ! empty($this->connection->host)
            && in_array($driver, PDO::getAvailableDrivers(), true);
    }
}
