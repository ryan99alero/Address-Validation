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
            $table->string('export_file_path')->nullable()->after('error_file_path');
            $table->string('export_status')->nullable()->after('export_file_path'); // pending, processing, completed, failed
            $table->timestamp('export_completed_at')->nullable()->after('export_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['export_file_path', 'export_status', 'export_completed_at']);
        });
    }
};
