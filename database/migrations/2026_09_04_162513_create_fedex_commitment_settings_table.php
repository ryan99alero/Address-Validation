<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row store for the editable FedEx-agreement commitment configuration: the six numeric
 * targets, the optional-membership toggles (Home Delivery / First Overnight / SameDay), and the
 * day-count denominator mode. Blank target columns fall back to config('fedex_commitments').
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fedex_commitment_settings', function (Blueprint $table) {
            $table->id();

            $table->decimal('express_avg_daily_packages', 10, 2)->nullable();
            $table->decimal('express_avg_daily_revenue', 12, 2)->nullable();
            $table->decimal('express_avg_charge_per_package', 12, 2)->nullable();

            $table->decimal('ground_avg_daily_packages', 10, 2)->nullable();
            $table->decimal('ground_avg_daily_revenue', 12, 2)->nullable();
            $table->decimal('ground_avg_charge_per_package', 12, 2)->nullable();

            $table->boolean('include_home_delivery')->default(true);
            $table->boolean('include_first_overnight')->default(false);
            $table->boolean('include_sameday')->default(false);

            $table->string('day_count_mode', 16)->default('business'); // business | calendar | active

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fedex_commitment_settings');
    }
};
