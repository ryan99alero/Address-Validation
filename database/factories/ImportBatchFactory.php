<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'original_filename' => fake()->word().'.csv',
            'file_path' => 'imports/'.fake()->uuid().'.csv',
            'status' => 'pending',
            'total_rows' => fake()->numberBetween(10, 100),
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ];
    }
}
