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
            // Required delivery date - when the shipment must arrive
            $table->date('required_delivery_date')->nullable()->after('ship_via_code_id');

            // Recommended service based on required delivery date
            $table->string('recommended_service', 100)->nullable()->after('required_delivery_date');
            $table->date('recommended_delivery_date')->nullable()->after('recommended_service');
            $table->boolean('can_meet_required_date')->nullable()->after('recommended_delivery_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn([
                'required_delivery_date',
                'recommended_service',
                'recommended_delivery_date',
                'can_meet_required_date',
            ]);
        });
    }
};
