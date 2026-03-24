<?php

namespace App\Console\Commands;

use App\Services\ShippingDatabaseService;
use Illuminate\Console\Command;

class TestShippingDatabase extends Command
{
    protected $signature = 'shipping:test
                            {tracking? : Optional tracking number to look up}';

    protected $description = 'Test the shipping database connection and lookup';

    public function handle(ShippingDatabaseService $shippingDb): int
    {
        $this->info('Testing Shipping Database Connection...');
        $this->newLine();

        // Check configuration
        $host = config('database.connections.shipping.host');
        $database = config('database.connections.shipping.database');

        if (empty($host) || $host === 'localhost') {
            $this->error('Shipping database not configured.');
            $this->line('Add these to your .env file:');
            $this->line('  SHIPPING_DB_HOST=your-server');
            $this->line('  SHIPPING_DB_PORT=1433');
            $this->line('  SHIPPING_DB_DATABASE=your-database');
            $this->line('  SHIPPING_DB_USERNAME=your-username');
            $this->line('  SHIPPING_DB_PASSWORD=your-password');

            return self::FAILURE;
        }

        $this->line("Host: {$host}");
        $this->line("Database: {$database}");
        $this->newLine();

        // Test connection
        $this->info('Testing connection...');
        if ($shippingDb->testConnection()) {
            $this->info('✓ Connection successful!');
        } else {
            $this->error('✗ Connection failed. Check your credentials and ensure pdo_sqlsrv extension is installed.');

            return self::FAILURE;
        }

        // Test lookup if tracking number provided
        $trackingNumber = $this->argument('tracking');
        if ($trackingNumber) {
            $this->newLine();
            $this->info("Looking up tracking number: {$trackingNumber}");

            $result = $shippingDb->lookupByTrackingNumber($trackingNumber);

            if ($result) {
                $this->info('✓ Found shipment:');
                $this->table(
                    ['Field', 'Value'],
                    collect($result)->map(fn ($v, $k) => [$k, $v ?? '(null)'])->toArray()
                );
            } else {
                $this->warn('No shipment found with that tracking number.');
            }
        }

        return self::SUCCESS;
    }
}
