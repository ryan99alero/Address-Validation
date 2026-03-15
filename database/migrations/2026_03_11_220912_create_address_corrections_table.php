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
        Schema::create('address_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers');
            $table->string('validation_status'); // valid, invalid, ambiguous
            $table->string('corrected_address_line_1')->nullable();
            $table->string('corrected_address_line_2')->nullable();
            $table->string('corrected_city')->nullable();
            $table->string('corrected_state')->nullable();
            $table->string('corrected_postal_code')->nullable();
            $table->string('corrected_postal_code_ext')->nullable(); // ZIP+4
            $table->string('corrected_country_code')->nullable();
            $table->boolean('is_residential')->nullable();
            $table->string('classification')->nullable(); // residential, commercial, mixed, unknown
            $table->decimal('confidence_score', 5, 2)->nullable(); // 0.00 - 100.00
            $table->integer('candidates_count')->default(0);
            $table->json('raw_response')->nullable(); // Full API response
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->index(['address_id', 'carrier_id']);
            $table->index('validation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_corrections');
    }
};
