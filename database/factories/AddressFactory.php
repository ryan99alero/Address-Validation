<?php

namespace Database\Factories;

use App\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_reference' => fake()->optional()->uuid(),
            'input_name' => fake()->name(),
            'input_company' => fake()->optional()->company(),
            'input_address_1' => fake()->streetAddress(),
            'input_address_2' => fake()->optional()->secondaryAddress(),
            'input_city' => fake()->city(),
            'input_state' => fake()->stateAbbr(),
            'input_postal' => fake()->postcode(),
            'input_country' => 'US',
            'source' => 'manual',
            'validation_status' => 'pending',
        ];
    }

    /**
     * Indicate that the address has been validated as valid.
     */
    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_status' => 'valid',
            'output_address_1' => $attributes['input_address_1'],
            'output_city' => $attributes['input_city'],
            'output_state' => $attributes['input_state'],
            'output_postal' => $attributes['input_postal'],
            'output_country' => $attributes['input_country'],
            'confidence_score' => fake()->randomFloat(2, 0.8, 1.0),
            'validated_at' => now(),
        ]);
    }

    /**
     * Indicate that the address is invalid.
     */
    public function invalid(): static
    {
        return $this->state(fn () => [
            'validation_status' => 'invalid',
            'confidence_score' => fake()->randomFloat(2, 0, 0.3),
            'validated_at' => now(),
        ]);
    }

    /**
     * Indicate that the address is ambiguous.
     */
    public function ambiguous(): static
    {
        return $this->state(fn () => [
            'validation_status' => 'ambiguous',
            'confidence_score' => fake()->randomFloat(2, 0.4, 0.7),
            'validated_at' => now(),
        ]);
    }

    /**
     * Add residential classification.
     */
    public function residential(): static
    {
        return $this->state(fn () => [
            'is_residential' => true,
            'classification' => 'residential',
        ]);
    }

    /**
     * Add commercial classification.
     */
    public function commercial(): static
    {
        return $this->state(fn () => [
            'is_residential' => false,
            'classification' => 'commercial',
        ]);
    }
}
