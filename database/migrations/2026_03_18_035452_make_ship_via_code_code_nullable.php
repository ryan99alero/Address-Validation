<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First make code nullable (unique constraint already exists)
        Schema::table('ship_via_codes', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->change();
        });

        // Then clear the code field for seeded records (they were using carrier shorthand)
        DB::table('ship_via_codes')->update(['code' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore carrier codes as the code value
        DB::table('ship_via_codes')->whereNull('code')->update([
            'code' => DB::raw('carrier_code'),
        ]);

        Schema::table('ship_via_codes', function (Blueprint $table) {
            $table->string('code', 50)->nullable(false)->change();
        });
    }
};
