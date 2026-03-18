<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Carrier;
use App\Models\TransitTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransitTime>
 */
class TransitTimeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'address_id' => Address::factory(),
            'carrier_id' => Carrier::factory(),
            'origin_postal_code' => fake()->postcode(),
            'origin_country_code' => 'US',
            'service_type' => 'FEDEX_GROUND',
            'service_name' => 'FedEx Ground',
            'carrier_code' => 'FDXG',
            'transit_days_description' => '3-5 Days',
            'delivery_date' => now()->addDays(5),
            'delivery_day_of_week' => 'MON',
            'calculated_at' => now(),
        ];
    }
}
