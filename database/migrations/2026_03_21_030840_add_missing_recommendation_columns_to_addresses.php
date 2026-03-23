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
            // Recommendation fields (when required_on_site_date is set)
            $table->string('recommended_service', 100)->nullable()->after('ground_date');
            $table->date('estimated_delivery_date')->nullable()->after('recommended_service');
            $table->boolean('can_meet_required_date')->nullable()->after('estimated_delivery_date');

            // Alternative suggestion (when ship_via doesn't meet deadline)
            $table->string('suggested_service', 100)->nullable()->after('can_meet_required_date');
            $table->date('suggested_delivery_date')->nullable()->after('suggested_service');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn([
                'recommended_service',
                'estimated_delivery_date',
                'can_meet_required_date',
                'suggested_service',
                'suggested_delivery_date',
            ]);
        });
    }
};
