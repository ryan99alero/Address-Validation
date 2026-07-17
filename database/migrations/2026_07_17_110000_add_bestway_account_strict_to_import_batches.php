<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Restrict to this account only" for a batch BestWay ship account. Off (default): BestWay may
 * cross to sibling accounts owned by the SAME owner on the plant (e.g. a split Ground + Express
 * pair). On: lock to exactly the chosen account's codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->boolean('bestway_account_strict')->default(false)->after('bestway_carrier_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('bestway_account_strict');
        });
    }
};
