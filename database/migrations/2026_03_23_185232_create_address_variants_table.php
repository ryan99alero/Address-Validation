<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table stores "bad" input address variants that map to corrected addresses.
     * Used for fast lookup: given an input address, find its correction.
     */
    public function up(): void
    {
        Schema::create('address_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corrected_address_id')->constrained()->cascadeOnDelete();

            // Original input address (normalized lowercase for matching)
            $table->string('input_address_1', 100);
            $table->string('input_address_2', 100)->nullable();
            $table->string('input_city', 50)->nullable();
            $table->string('input_state', 50)->nullable();
            $table->string('input_postal', 20);
            $table->string('input_country', 2)->default('us');

            // Hash for fast exact-match lookup
            $table->string('input_hash', 64);

            // Usage tracking
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->timestamps();

            // Primary lookup: postal narrows search space, then hash for exact match
            // This is the key to performance - postal reduces to ~0.003% of records
            $table->unique(['input_postal', 'input_hash'], 'variant_postal_hash_unique');
            $table->index('input_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_variants');
    }
};
