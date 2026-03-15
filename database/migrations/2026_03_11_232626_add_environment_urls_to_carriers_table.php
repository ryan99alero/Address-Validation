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
        Schema::table('carriers', function (Blueprint $table) {
            // Add separate URL fields for each environment
            $table->string('sandbox_url')->nullable()->after('environment');
            $table->string('production_url')->nullable()->after('sandbox_url');

            // Remove the old base_url column if it exists
            if (Schema::hasColumn('carriers', 'base_url')) {
                $table->dropColumn('base_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->dropColumn(['sandbox_url', 'production_url']);
            $table->string('base_url')->nullable()->after('environment');
        });
    }
};
