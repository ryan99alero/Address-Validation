<?php

namespace Database\Factories;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Carrier>
 */
class CarrierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'is_active' => true,
            'environment' => 'sandbox',
            'sandbox_url' => fake()->url(),
            'production_url' => fake()->url(),
            'auth_type' => 'oauth2',
            'auth_credentials' => null,
            'timeout_seconds' => 30,
        ];
    }
}
