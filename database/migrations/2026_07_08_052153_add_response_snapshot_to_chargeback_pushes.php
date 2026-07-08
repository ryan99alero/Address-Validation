<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the full JobCost record Pace returns on create (all fields incl. the new ID), as an audit
 * snapshot alongside the extracted pace_jobcost_id. Lets finance see exactly what posted without a
 * re-read, and backs the ledger-vs-Pace reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->json('response_snapshot')->nullable()->after('pace_jobcost_id');
        });
    }

    public function down(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->dropColumn('response_snapshot');
        });
    }
};
