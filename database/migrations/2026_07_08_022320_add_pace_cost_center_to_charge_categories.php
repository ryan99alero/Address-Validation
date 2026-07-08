<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Pace cost center each fee category posts to when the recoup/chargeback push runs. The driver
 * decides WHICH charges to push (disposition/push flag); the category decides WHERE they land — so
 * e.g. the address-correction fee and its fuel break out to their own cost centers automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge_categories', function (Blueprint $table): void {
            $table->string('pace_cost_center')->nullable()->after('abbreviation');
        });
    }

    public function down(): void
    {
        Schema::table('charge_categories', function (Blueprint $table): void {
            $table->dropColumn('pace_cost_center');
        });
    }
};
