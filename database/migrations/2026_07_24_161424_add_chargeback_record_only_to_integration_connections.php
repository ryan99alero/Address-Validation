<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            // When true (and chargeback_push_enabled is on), the engine resolves and writes every
            // chargeback ledger row (job/customer/CSR/salesperson) but never creates a Pace JobCost —
            // records for the closed-job billing export without any external ERP write.
            $table->boolean('chargeback_record_only')->default(false)->after('chargeback_push_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn('chargeback_record_only');
        });
    }
};
