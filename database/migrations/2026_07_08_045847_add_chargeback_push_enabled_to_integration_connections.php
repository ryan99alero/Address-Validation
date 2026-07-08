<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master temp-disable for the customer chargeback (JobCost) push, per ERP connection. Default OFF —
 * a money path is opt-in. Checked live at push time: OFF → the import-triggered push is a no-op
 * (records are ignored, not held); ON → only new imports push. Each push-back feature gets its own
 * boolean here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->boolean('chargeback_push_enabled')->default(false)->after('dry_run');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn('chargeback_push_enabled');
        });
    }
};
