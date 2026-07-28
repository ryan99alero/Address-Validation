<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (good address, carrier): "does THIS carrier accept this address without an ADC
        // fee, and when did we last confirm it". Carrier is a field, not a column-per-carrier or a
        // table-per-carrier — adding DHL/USPS later adds rows, never schema.
        Schema::create('address_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corrected_address_id')->constrained('corrected_addresses')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->string('status', 20);              // verified | drifted | failed
            $table->timestamp('verified_at')->nullable();  // last clean pass (untouched by failed attempts)
            $table->timestamp('checked_at')->nullable();   // last attempt of any outcome (drives backoff)
            $table->string('source', 20)->nullable();      // api | invoice
            $table->json('result_snapshot')->nullable();   // carrier's returned form when it disagreed
            $table->timestamps();

            $table->unique(['corrected_address_id', 'carrier_id']);
            $table->index(['carrier_id', 'status', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_verifications');
    }
};
