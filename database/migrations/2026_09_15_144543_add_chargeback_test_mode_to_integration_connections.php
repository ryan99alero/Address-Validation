<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Test mode for the chargeback push: when on (and Audit-only is off), JobCosts post ONLY for the
     * customer ids listed here — every other job resolves and records the ledger row but is held
     * (skipped_test_mode), never posted, until its customer id is added and the row is re-driven. Lets
     * a live billing pilot be scoped to a handful of customers for a few weeks.
     */
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->boolean('chargeback_test_mode')->default(false)->after('chargeback_record_only');
            $table->text('chargeback_test_customer_ids')->nullable()->after('chargeback_test_mode');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn(['chargeback_test_mode', 'chargeback_test_customer_ids']);
        });
    }
};
