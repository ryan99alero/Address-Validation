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
        if (Schema::hasColumn('import_batches', 'find_best_service')) {
            return;
        }

        Schema::table('import_batches', function (Blueprint $table) {
            $table->boolean('find_best_service')->default(false)->after('origin_country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('find_best_service');
        });
    }
};
