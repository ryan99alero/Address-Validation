<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-aggregated rollups of the 3.4M-row carrier_charges table, by carrier ×
 * category × year (and carrier × year for distinct-ship denominators). The
 * report pages read these few-thousand rows instead of scanning the raw table,
 * so every filter computes instantly. Fully rebuilt from current data on each
 * refresh, so adds and deletes are both reflected without reversal entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_charge_rollup', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_category_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('year');
            $table->unsignedInteger('charge_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedInteger('distinct_ships')->default(0);
            $table->timestamps();

            $table->index(['carrier_id', 'year']);
            $table->index('charge_category_id');
        });

        Schema::create('carrier_ship_rollup', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');
            $table->unsignedInteger('total_ships')->default(0);
            $table->unsignedInteger('aux_ships')->default(0);
            $table->timestamps();

            $table->unique(['carrier_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_charge_rollup');
        Schema::dropIfExists('carrier_ship_rollup');
    }
};
