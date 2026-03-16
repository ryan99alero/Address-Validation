<?php

namespace Database\Seeders;

use App\Models\Carrier;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Carrier::updateOrCreate(
            ['slug' => 'ups'],
            [
                'name' => 'UPS',
                'is_active' => true,
                'environment' => 'sandbox',
                'auth_type' => 'oauth2',
                'timeout_seconds' => 30,
                // UPS doesn't support native batch - use concurrent requests
                'chunk_size' => 100,
                'concurrent_requests' => 10,
                'rate_limit_per_minute' => null,
                'supports_native_batch' => false,
                'native_batch_size' => null,
            ]
        );

        Carrier::updateOrCreate(
            ['slug' => 'fedex'],
            [
                'name' => 'FedEx',
                'is_active' => true,
                'environment' => 'sandbox',
                'auth_type' => 'oauth2',
                'timeout_seconds' => 30,
                // FedEx supports native batch
                'chunk_size' => 100,
                'concurrent_requests' => 5,
                'rate_limit_per_minute' => null,
                'supports_native_batch' => true,
                'native_batch_size' => 100,
            ]
        );

        Carrier::updateOrCreate(
            ['slug' => 'smarty'],
            [
                'name' => 'Smarty',
                'is_active' => true,
                'environment' => 'production',
                'auth_type' => 'api_key',
                'timeout_seconds' => 30,
                // Smarty supports native batch up to 100 addresses
                'chunk_size' => 100,
                'concurrent_requests' => 5,
                'rate_limit_per_minute' => null,
                'supports_native_batch' => true,
                'native_batch_size' => 100,
            ]
        );
    }
}
