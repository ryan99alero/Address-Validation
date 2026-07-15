<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ship date sent to FedEx for this quote. FedEx honors a future shipDatestamp and
 * returns a holiday/weekend-aware delivery date relative to it, so transit duration must
 * be measured from THIS date — not the fetch date (calculated_at), which was only correct
 * back when the mis-cased key made FedEx compute everything from "today".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_times', function (Blueprint $table): void {
            $table->date('ship_date')->nullable()->after('delivery_day_of_week');
        });
    }

    public function down(): void
    {
        Schema::table('transit_times', function (Blueprint $table): void {
            $table->dropColumn('ship_date');
        });
    }
};
