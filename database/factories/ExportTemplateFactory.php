<?php

namespace Database\Factories;

use App\Models\ExportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportTemplate>
 */
class ExportTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Export',
            'description' => fake()->sentence(),
            'target_system' => fake()->randomElement(['epace', 'ups_worldship', 'fedex_ship', 'generic']),
            'field_layout' => [
                ['field' => 'external_reference', 'header' => 'RefNum', 'position' => 1],
                ['field' => 'corrected_address_line_1', 'header' => 'Address', 'position' => 2],
                ['field' => 'corrected_city', 'header' => 'City', 'position' => 3],
                ['field' => 'corrected_state', 'header' => 'State', 'position' => 4],
                ['field' => 'corrected_postal_code', 'header' => 'ZIP', 'position' => 5],
            ],
            'file_format' => ExportTemplate::FORMAT_CSV,
            'delimiter' => ',',
            'include_header' => true,
            'is_shared' => false,
        ];
    }
}
