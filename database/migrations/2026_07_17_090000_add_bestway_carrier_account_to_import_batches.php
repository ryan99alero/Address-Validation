<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-batch BestWay ship account: when set, BestWay picks services only from this
 * carrier_account's ShipVia codes (on the chosen plant) rather than deriving the account from
 * each row's original code. Lets the user say "ship this whole batch on account X".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->foreignId('bestway_carrier_account_id')->nullable()->after('bestway_plant_id')->constrained('carrier_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bestway_carrier_account_id');
        });
    }
};
