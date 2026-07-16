<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse-Validate Future Date: an opt-in second FedEx call that re-quotes the BestWay
 * ship date to confirm FedEx's ACTUAL committed delivery (holiday-aware) instead of the
 * inferred required date. Costs one extra API call per future-dated shipment.
 *
 *  - import_batches.reverse_validate_future_date — the toggle.
 *  - addresses.bestway_service_type — the FedEx service the JIT picked (needed to re-quote).
 *  - addresses.arrival_verified — true = FedEx confirmed on-time, false = it slipped
 *    (flagged for review), null = not checked / couldn't verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->boolean('reverse_validate_future_date')->default(false)->after('find_best_service');
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('bestway_service_type')->nullable()->after('recommended_ship_service');
            $table->boolean('arrival_verified')->nullable()->after('bestway_service_type');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('reverse_validate_future_date');
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn(['bestway_service_type', 'arrival_verified']);
        });
    }
};
