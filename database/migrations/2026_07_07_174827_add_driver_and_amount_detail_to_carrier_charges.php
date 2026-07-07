<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "driver" dimension (WHY a charge exists — address correction, DIM audit, return…)
 * alongside the existing category (WHAT it is), plus the published/incentive amounts UPS prints
 * per line (needed for claim math and currently discarded on save).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table): void {
            // The charge's driver key (see App\Enums\ChargeDriver) and how it was determined
            // (csv_code | pdf_section | description | default | manual). Nullable = unattributed.
            $table->string('driver', 40)->nullable()->after('charge_category_id');
            $table->string('driver_source', 20)->nullable()->after('driver');
            // Pre-incentive list charge and the incentive/credit applied — evidence for disputes.
            $table->decimal('published', 12, 2)->nullable()->after('amount');
            $table->decimal('incentive', 12, 2)->nullable()->after('published');

            $table->index(['carrier_id', 'driver', 'charge_category_id'], 'carrier_charges_driver_idx');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table): void {
            $table->dropIndex('carrier_charges_driver_idx');
            $table->dropColumn(['driver', 'driver_source', 'published', 'incentive']);
        });
    }
};
