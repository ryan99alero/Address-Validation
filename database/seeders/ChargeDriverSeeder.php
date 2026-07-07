<?php

namespace Database\Seeders;

use App\Enums\ChargeDriver;
use App\Models\ChargeDriver as ChargeDriverModel;
use Illuminate\Database\Seeder;

/**
 * Seeds the driver catalog from the ChargeDriver enum defaults. Idempotent and non-destructive:
 * uses firstOrCreate keyed on the driver value, so re-running never overwrites an operator's
 * Pace-code / disposition edits.
 */
class ChargeDriverSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ChargeDriver::cases() as $i => $driver) {
            ChargeDriverModel::firstOrCreate(
                ['key' => $driver->value],
                [
                    'label' => $driver->label(),
                    'abbreviation' => $driver->abbreviation(),
                    'disposition' => $driver->disposition(),
                    'color' => $driver->color(),
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
