<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a billing-type dimension to the charge rollup so the Fee Summary can
     * split third-party vs on-account. Null = charges with no tracking
     * (account-level fees) — unclassified. Rebuilt by CarrierRollupService.
     */
    public function up(): void
    {
        Schema::table('carrier_charge_rollup', function (Blueprint $table) {
            $table->boolean('is_third_party')->nullable()->after('charge_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charge_rollup', function (Blueprint $table) {
            $table->dropColumn('is_third_party');
        });
    }
};
