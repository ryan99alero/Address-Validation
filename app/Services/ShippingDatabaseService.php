<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShippingDatabaseService
{
    protected string $connection = 'shipping';

    protected string $table = 'xCarrierShipping';

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
                ->where('trackingno', $trackingNumber)
                ->first();

            if ($result) {
                return (array) $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Shipping DB lookup failed', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Batch lookup multiple shipments by tracking numbers.
     * Returns array keyed by tracking number.
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
                ->select(['trackingno', 'company', 'contact', 'add1', 'add2', 'city', 'state', 'zipcode', 'country'])
                ->whereIn('trackingno', $trackingNumbers)
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
     * Test the database connection.
     */
    public function testConnection(): bool
    {
        try {
            DB::connection($this->connection)->getPdo();

            return true;
        } catch (\Exception $e) {
            Log::error('Shipping DB connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the shipping database is configured and available.
     */
    public function isAvailable(): bool
    {
        $host = config('database.connections.shipping.host');

        return ! empty($host) && $host !== 'localhost';
    }
}
