<?php

namespace Database\Factories;

use App\Models\AddressCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddressCorrection>
 */
class AddressCorrectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'validation_status' => AddressCorrection::STATUS_VALID,
            'corrected_address_line_1' => fake()->streetAddress(),
            'corrected_address_line_2' => fake()->optional()->secondaryAddress(),
            'corrected_city' => fake()->city(),
            'corrected_state' => fake()->stateAbbr(),
            'corrected_postal_code' => fake()->postcode(),
            'corrected_postal_code_ext' => fake()->optional()->numerify('####'),
            'corrected_country_code' => 'US',
            'is_residential' => fake()->boolean(),
            'classification' => fake()->randomElement([
                AddressCorrection::CLASSIFICATION_RESIDENTIAL,
                AddressCorrection::CLASSIFICATION_COMMERCIAL,
                AddressCorrection::CLASSIFICATION_UNKNOWN,
            ]),
            'confidence_score' => fake()->randomFloat(2, 0, 1),
            'candidates_count' => fake()->numberBetween(1, 5),
            'raw_response' => [],
            'validated_at' => now(),
        ];
    }
}
