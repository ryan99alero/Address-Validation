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
            // Column name in the import that contains the ship via code
            $table->string('ship_via_code_column')->nullable()->after('origin_country_code');
        });

        // Also add to import field templates for saving the mapping
        Schema::table('import_field_templates', function (Blueprint $table) {
            $table->string('ship_via_code_field')->nullable()->after('field_mappings');
        });

        // Add ship_via_code to addresses table for storing the resolved code
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('ship_via_code', 50)->nullable()->after('country_code');
            $table->foreignId('ship_via_code_id')->nullable()->after('ship_via_code')
                ->constrained('ship_via_codes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ship_via_code_id');
            $table->dropColumn('ship_via_code');
        });

        Schema::table('import_field_templates', function (Blueprint $table) {
            $table->dropColumn('ship_via_code_field');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('ship_via_code_column');
        });
    }
};
