<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ensure transit days columns exist as varchar to support ranges like "2-3".
     */
    public function up(): void
    {
        $hasShipViaDays = Schema::hasColumn('addresses', 'ship_via_days');
        $hasFastestDays = Schema::hasColumn('addresses', 'fastest_days');
        $hasGroundDays = Schema::hasColumn('addresses', 'ground_days');

        // Change existing columns to varchar
        if ($hasShipViaDays || $hasFastestDays || $hasGroundDays) {
            Schema::table('addresses', function (Blueprint $table) use ($hasShipViaDays, $hasFastestDays, $hasGroundDays) {
                if ($hasShipViaDays) {
                    $table->string('ship_via_days', 10)->nullable()->change();
                }
                if ($hasFastestDays) {
                    $table->string('fastest_days', 10)->nullable()->change();
                }
                if ($hasGroundDays) {
                    $table->string('ground_days', 10)->nullable()->change();
                }
            });
        }

        // Add missing columns as varchar
        if (! $hasShipViaDays || ! $hasFastestDays || ! $hasGroundDays) {
            Schema::table('addresses', function (Blueprint $table) use ($hasShipViaDays, $hasFastestDays, $hasGroundDays) {
                if (! $hasShipViaDays) {
                    $table->string('ship_via_days', 10)->nullable();
                }
                if (! $hasFastestDays) {
                    $table->string('fastest_days', 10)->nullable();
                }
                if (! $hasGroundDays) {
                    $table->string('ground_days', 10)->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'ship_via_days')) {
                $table->smallInteger('ship_via_days')->unsigned()->nullable()->change();
            }
            if (Schema::hasColumn('addresses', 'fastest_days')) {
                $table->smallInteger('fastest_days')->unsigned()->nullable()->change();
            }
            if (Schema::hasColumn('addresses', 'ground_days')) {
                $table->smallInteger('ground_days')->unsigned()->nullable()->change();
            }
        });
    }
};
