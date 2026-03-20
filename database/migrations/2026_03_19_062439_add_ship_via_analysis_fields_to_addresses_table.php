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
        Schema::table('addresses', function (Blueprint $table) {
            // Ship Via analysis fields - stored for efficient export
            $table->string('ship_via_service_name', 100)->nullable()->after('ship_via_code_id');
            $table->string('ship_via_transit_days', 50)->nullable()->after('ship_via_service_name');
            $table->date('ship_via_delivery_date')->nullable()->after('ship_via_transit_days');
            $table->boolean('ship_via_meets_deadline')->nullable()->after('ship_via_delivery_date');

            // Alternative suggestion when ship_via doesn't meet deadline
            $table->string('suggested_service', 100)->nullable()->after('can_meet_required_date');
            $table->date('suggested_delivery_date')->nullable()->after('suggested_service');

            // Distance from origin (stored for export efficiency)
            $table->decimal('distance_miles', 10, 2)->nullable()->after('suggested_delivery_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn([
                'ship_via_service_name',
                'ship_via_transit_days',
                'ship_via_delivery_date',
                'ship_via_meets_deadline',
                'suggested_service',
                'suggested_delivery_date',
                'distance_miles',
            ]);
        });
    }
};
