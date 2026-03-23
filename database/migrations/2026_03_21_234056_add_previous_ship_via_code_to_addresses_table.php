<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if columns already exist
        if (Schema::hasColumn('addresses', 'previous_ship_via_code')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $table) {
            // Store original ship_via_code before BestWay optimization replaces it
            $table->string('previous_ship_via_code', 50)->nullable()->after('ship_via_code');

            // Flag to indicate if BestWay optimization was applied
            $table->boolean('bestway_optimized')->default(false)->after('previous_ship_via_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['previous_ship_via_code', 'bestway_optimized']);
        });
    }
};
