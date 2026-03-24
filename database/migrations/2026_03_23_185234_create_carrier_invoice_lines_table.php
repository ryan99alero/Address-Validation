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
        Schema::create('carrier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_invoice_id')->constrained()->cascadeOnDelete();

            // Shipment identification
            $table->string('tracking_number', 50);
            $table->date('ship_date')->nullable();
            $table->date('delivery_date')->nullable();

            // Original (input) address - what was submitted
            $table->string('original_name', 100)->nullable();
            $table->string('original_company', 100)->nullable();
            $table->string('original_address_1', 100)->nullable();
            $table->string('original_address_2', 100)->nullable();
            $table->string('original_address_3', 100)->nullable();
            $table->string('original_city', 50)->nullable();
            $table->string('original_state', 50)->nullable();
            $table->string('original_postal', 20)->nullable();
            $table->string('original_country', 2)->default('US');

            // Corrected address - what carrier corrected to
            $table->string('corrected_address_1', 100)->nullable();
            $table->string('corrected_address_2', 100)->nullable();
            $table->string('corrected_address_3', 100)->nullable();
            $table->string('corrected_city', 50)->nullable();
            $table->string('corrected_state', 50)->nullable();
            $table->string('corrected_postal', 20)->nullable();
            $table->string('corrected_country', 2)->default('US');

            // Charge details
            $table->string('charge_code', 20)->nullable();
            $table->string('charge_description', 100)->nullable();
            $table->decimal('charge_amount', 8, 2)->default(0);

            // Link to our address correction cache (populated after processing)
            $table->foreignId('corrected_address_id')->nullable()
                ->constrained('corrected_addresses')->nullOnDelete();

            // Billing back to customer/Pace
            $table->boolean('billed_to_pace')->default(false);
            $table->timestamp('billed_at')->nullable();
            $table->string('pace_job_number', 50)->nullable();
            $table->string('pace_customer_id', 50)->nullable();

            $table->timestamps();

            // Indexes for lookups
            $table->index('tracking_number');
            $table->index('ship_date');
            $table->index('billed_to_pace');
            $table->index(['original_postal', 'original_address_1']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_invoice_lines');
    }
};
