<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the charge rollup's billing dimension from the 2-way is_third_party to the 3-way
     * billing_type (prepaid | collect | third_party), so the Carrier Fee Summary can break out
     * Collect alongside Prepaid / 3rd Party. Derived from carrier_shipments.billing_type (the same
     * invoice-authoritative source the All Charges / All Shipments filters use). Null = charges with
     * no tracking (account-level fees). Rebuilt by CarrierRollupService.
     */
    public function up(): void
    {
        Schema::table('carrier_charge_rollup', function (Blueprint $table): void {
            $table->string('billing_type', 16)->nullable()->after('is_third_party');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charge_rollup', function (Blueprint $table): void {
            $table->dropColumn('billing_type');
        });
    }
};
