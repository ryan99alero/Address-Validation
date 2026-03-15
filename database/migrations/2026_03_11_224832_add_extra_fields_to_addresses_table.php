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
        // Add extra fields to addresses table
        Schema::table('addresses', function (Blueprint $table) {
            for ($i = 1; $i <= 20; $i++) {
                $table->string("extra_{$i}")->nullable();
            }
        });

        // Add extra fields to address_corrections table
        Schema::table('address_corrections', function (Blueprint $table) {
            for ($i = 1; $i <= 20; $i++) {
                $table->string("extra_{$i}")->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            for ($i = 1; $i <= 20; $i++) {
                $table->dropColumn("extra_{$i}");
            }
        });

        Schema::table('address_corrections', function (Blueprint $table) {
            for ($i = 1; $i <= 20; $i++) {
                $table->dropColumn("extra_{$i}");
            }
        });
    }
};
