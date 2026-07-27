<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shipping heatmap filters ~1M carrier_shipments rows by ship_date year/month; without an index
 * the period filter full-scans the table. Index ship_date so the year/month slice is cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table) {
            $table->index('ship_date', 'carrier_shipments_ship_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table) {
            $table->dropIndex('carrier_shipments_ship_date_index');
        });
    }
};
