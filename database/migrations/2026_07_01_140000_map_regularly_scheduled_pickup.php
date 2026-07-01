<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Categorize FedEx "Regularly Scheduled Pickup" (was Uncategorized) under
 * Weekly / Service Charge. Guarded so it no-ops on a fresh test DB with no
 * seeded categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        $categoryId = DB::table('charge_categories')->where('name', 'Weekly / Service Charge')->value('id');
        if ($categoryId === null) {
            return;
        }

        $exists = DB::table('charge_code_mappings')
            ->where('match_type', 'description')
            ->where('match_value', 'Regularly Scheduled Pickup')
            ->exists();

        if (! $exists) {
            DB::table('charge_code_mappings')->insert([
                'carrier_id' => null,
                'match_type' => 'description',
                'match_value' => 'Regularly Scheduled Pickup',
                'charge_category_id' => $categoryId,
                'priority' => 50,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('charge_code_mappings')
            ->where('match_type', 'description')
            ->where('match_value', 'Regularly Scheduled Pickup')
            ->delete();
    }
};
