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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('external_reference')->nullable();

            // ORIGINAL INPUT
            $table->string('input_name')->nullable();
            $table->string('input_company')->nullable();
            $table->string('input_address_1')->nullable();
            $table->string('input_address_2')->nullable();
            $table->string('input_city')->nullable();
            $table->string('input_state', 50)->nullable();
            $table->string('input_postal', 20)->nullable();
            $table->string('input_country', 2)->default('US');

            // VALIDATED OUTPUT (denormalized from corrections)
            $table->string('output_address_1')->nullable();
            $table->string('output_address_2')->nullable();
            $table->string('output_city')->nullable();
            $table->string('output_state', 50)->nullable();
            $table->string('output_postal', 20)->nullable();
            $table->string('output_postal_ext', 10)->nullable();
            $table->string('output_country', 2)->nullable();

            // VALIDATION RESULT
            $table->enum('validation_status', ['pending', 'valid', 'invalid', 'ambiguous'])->default('pending');
            $table->boolean('is_residential')->nullable();
            $table->string('classification', 20)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->foreignId('validated_by_carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();

            // TRANSIT SUMMARY (denormalized from transit_times)
            $table->string('ship_via_code', 50)->nullable();
            $table->foreignId('ship_via_code_id')->nullable()->constrained('ship_via_codes')->nullOnDelete();
            $table->string('ship_via_service', 100)->nullable();
            $table->smallInteger('ship_via_days')->unsigned()->nullable();
            $table->date('ship_via_date')->nullable();
            $table->boolean('ship_via_meets_deadline')->nullable();

            $table->string('fastest_service', 100)->nullable();
            $table->smallInteger('fastest_days')->unsigned()->nullable();
            $table->date('fastest_date')->nullable();

            $table->string('ground_service', 100)->nullable();
            $table->smallInteger('ground_days')->unsigned()->nullable();
            $table->date('ground_date')->nullable();

            // Recommendation fields (when required_on_site_date is set)
            $table->string('recommended_service', 100)->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->boolean('can_meet_required_date')->nullable();

            // Alternative suggestion (when ship_via doesn't meet deadline)
            $table->string('suggested_service', 100)->nullable();
            $table->date('suggested_delivery_date')->nullable();

            $table->decimal('distance_miles', 10, 2)->nullable();

            // SHIPPING DATES
            $table->date('requested_ship_date')->nullable();
            $table->date('required_on_site_date')->nullable();

            // FLEXIBLE EXTRA DATA (JSON instead of 50 varchar columns)
            $table->json('extra_data')->nullable();

            // SOURCE TRACKING
            $table->string('source', 50)->default('import');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // INDEXES
            $table->index(['import_batch_id', 'source_row_number'], 'addr_batch_order');
            $table->index(['import_batch_id', 'validation_status'], 'addr_batch_status');
            $table->index('ship_via_code', 'addr_ship_via');
            $table->index('validated_at', 'addr_validated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
