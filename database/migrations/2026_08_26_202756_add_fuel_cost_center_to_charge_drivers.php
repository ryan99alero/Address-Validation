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
        Schema::table('charge_drivers', function (Blueprint $table) {
            $table->string('fuel_cost_center', 16)->nullable()->after('pace_activity_code');
        });

        // Seed the one split that isn't the fuel-category default: fuel that rode in on a DIM/weight
        // audit books to its own cost center. Address & residential correction fuel stay blank and
        // fall back to the Fuel Surcharge category's default. Idempotent.
        DB::table('charge_drivers')->where('key', 'audit_correction')->update(['fuel_cost_center' => '72550']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge_drivers', function (Blueprint $table) {
            $table->dropColumn('fuel_cost_center');
        });
    }
};
