<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Recreates the transit_times table after it was accidentally dropped
     * during the schema migration cleanup.
     */
    public function up(): void
    {
        // Skip if table already exists (different environments may have different states)
        if (Schema::hasTable('transit_times')) {
            return;
        }

        Schema::create('transit_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();

            // Origin address used for transit calculation
            $table->string('origin_postal_code', 10);
            $table->string('origin_country_code', 2)->default('US');

            // Service information
            $table->string('service_type', 50);
            $table->string('service_name', 100);
            $table->string('carrier_code', 10)->nullable();

            // Transit time details
            $table->string('transit_days_description', 50)->nullable();
            $table->string('minimum_transit_time', 30)->nullable();
            $table->string('maximum_transit_time', 30)->nullable();

            // Delivery commitment
            $table->date('delivery_date')->nullable();
            $table->time('delivery_time')->nullable();
            $table->string('delivery_day_of_week', 10)->nullable();
            $table->time('cutoff_time')->nullable();

            // Distance
            $table->decimal('distance_value', 10, 2)->nullable();
            $table->string('distance_units', 5)->nullable();

            // Raw API response for reference
            $table->json('raw_response')->nullable();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            // Index for quick lookups
            $table->index(['address_id', 'carrier_id']);
            $table->index(['service_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transit_times');
    }
};
