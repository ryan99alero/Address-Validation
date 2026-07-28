<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threshold for the correction-cache Pace lookup: only bad addresses corrected at least this many
 * times get a (costly) live Carton lookup to resolve Job/Customer/CSR/Sales Rep. Adjustable on the
 * Pace connection; default 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->unsignedInteger('correction_cache_min_lookup')->default(5)->after('chargeback_record_only');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->dropColumn('correction_cache_min_lookup');
        });
    }
};
