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
            ]
        );
    }
}
