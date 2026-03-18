<?php

namespace Database\Factories;

use App\Models\Carrier;
use App\Models\ShipViaCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipViaCode>
 */
class ShipViaCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => null,
            'carrier_code' => fake()->randomElement(['FDG', 'GND', '2DA', 'NDA']),
            'alternate_codes' => null,
            'carrier_id' => Carrier::factory(),
            'service_type' => fake()->randomElement(['FEDEX_GROUND', 'GND', '2DA', 'NDA']),
            'service_name' => fake()->randomElement(['FedEx Ground', 'UPS Ground', 'UPS 2nd Day Air']),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Set a user code.
     */
    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $code,
        ]);
    }

    /**
     * Set alternate codes.
     *
     * @param  array<string>  $codes
     */
    public function withAlternateCodes(array $codes): static
    {
        return $this->state(fn (array $attributes) => [
            'alternate_codes' => $codes,
        ]);
    }

    /**
     * Set as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Configure for FedEx Ground.
     */
    public function fedexGround(): static
    {
        return $this->state(fn (array $attributes) => [
            'carrier_code' => 'FDG',
            'service_type' => 'FEDEX_GROUND',
            'service_name' => 'FedEx Ground',
        ]);
    }

    /**
     * Configure for UPS Ground.
     */
    public function upsGround(): static
    {
        return $this->state(fn (array $attributes) => [
            'carrier_code' => 'GND',
            'service_type' => 'GND',
            'service_name' => 'UPS Ground',
        ]);
    }
}
