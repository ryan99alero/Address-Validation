<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            // Current processing phase: importing, validating, fetching_transit_times, complete
            $table->string('processing_phase', 50)->nullable()->after('validated_rows');

            // Transit time progress tracking
            $table->integer('transit_time_rows')->default(0)->after('processing_phase');
            $table->integer('total_for_transit')->default(0)->after('transit_time_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['processing_phase', 'transit_time_rows', 'total_for_transit']);
        });
    }
};
