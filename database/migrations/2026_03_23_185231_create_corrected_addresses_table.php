<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table stores canonical "good" corrected addresses.
     * Multiple bad input addresses (variants) can point to one corrected address.
     */
    public function up(): void
    {
        Schema::create('corrected_addresses', function (Blueprint $table) {
            $table->id();

            // Canonical corrected address (normalized lowercase)
            $table->string('address_1', 100);
            $table->string('address_2', 100)->nullable();
            $table->string('address_3', 100)->nullable();
            $table->string('city', 50);
            $table->string('state', 2);
            $table->string('postal', 10);
            $table->string('postal_ext', 4)->nullable();
            $table->string('country', 2)->default('us');

            // Hash of normalized address for fast deduplication
            $table->string('address_hash', 64)->unique();

            // Source tracking
            $table->foreignId('first_carrier_id')->nullable()
                ->constrained('carriers')->nullOnDelete();
            $table->boolean('is_residential')->nullable();

            // Usage statistics
            $table->unsignedInteger('usage_count')->default(1);
            $table->unsignedInteger('variant_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Index for geographic queries
            $table->index('postal');
            $table->index(['state', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corrected_addresses');
    }
};
