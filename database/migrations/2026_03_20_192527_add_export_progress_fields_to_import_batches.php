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
            // Track export progress
            $table->unsignedInteger('export_total_rows')->nullable()->after('export_status');
            $table->unsignedInteger('export_processed_rows')->default(0)->after('export_total_rows');
            $table->string('export_phase', 50)->nullable()->after('export_processed_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['export_total_rows', 'export_processed_rows', 'export_phase']);
        });
    }
};
