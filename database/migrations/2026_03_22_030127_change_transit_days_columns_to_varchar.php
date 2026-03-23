<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change transit days columns from smallint to varchar to support ranges like "2-3".
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('ship_via_days', 10)->nullable()->change();
            $table->string('fastest_days', 10)->nullable()->change();
            $table->string('ground_days', 10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->smallInteger('ship_via_days')->unsigned()->nullable()->change();
            $table->smallInteger('fastest_days')->unsigned()->nullable()->change();
            $table->smallInteger('ground_days')->unsigned()->nullable()->change();
        });
    }
};
