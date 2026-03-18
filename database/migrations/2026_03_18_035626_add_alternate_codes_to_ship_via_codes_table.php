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
        Schema::table('ship_via_codes', function (Blueprint $table) {
            // JSON array of alternate codes that should also match this service
            // e.g., ["FDXG", "GROUND", "FXG"] for FedEx Ground
            $table->json('alternate_codes')->nullable()->after('carrier_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ship_via_codes', function (Blueprint $table) {
            $table->dropColumn('alternate_codes');
        });
    }
};
