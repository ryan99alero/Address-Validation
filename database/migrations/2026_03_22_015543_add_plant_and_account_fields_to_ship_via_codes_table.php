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
        if (Schema::hasColumn('ship_via_codes', 'plant_id')) {
            return;
        }

        Schema::table('ship_via_codes', function (Blueprint $table) {
            // Plant identifier (e.g., Plant001, Plant002, Plant003)
            $table->string('plant_id', 50)->nullable()->after('description');

            // Payment type: sender (use our account) or third_party (bill to client)
            $table->enum('payment_type', ['sender', 'third_party'])->nullable()->after('plant_id');

            // Account number - used when payment_type is 'sender' to identify which carrier account
            $table->string('account_number', 50)->nullable()->after('payment_type');

            // Composite index for BestWay lookups
            $table->index(['service_type', 'plant_id', 'payment_type', 'account_number'], 'ship_via_bestway_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ship_via_codes', function (Blueprint $table) {
            $table->dropIndex('ship_via_bestway_lookup');
            $table->dropColumn(['plant_id', 'payment_type', 'account_number']);
        });
    }
};
