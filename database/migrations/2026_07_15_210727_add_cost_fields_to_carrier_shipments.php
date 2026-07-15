<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-shipment base/fee cost split derived from the shipment's charges, so
     * carrier_shipments carries everything the Per-Shipment Costs view needs and
     * the separate carrier_shipment_summary table can be retired.
     */
    public function up(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table) {
            $table->decimal('base_amount', 12, 2)->nullable()->after('printed_total');
            $table->decimal('fee_amount', 12, 2)->nullable()->after('base_amount');
            $table->string('fee_abbrevs')->nullable()->after('fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'fee_amount', 'fee_abbrevs']);
        });
    }
};
