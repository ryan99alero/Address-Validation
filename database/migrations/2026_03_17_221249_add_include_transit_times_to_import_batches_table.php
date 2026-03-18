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
            $table->boolean('include_transit_times')->default(false)->after('carrier_id');
            $table->string('origin_postal_code', 10)->nullable()->after('include_transit_times');
            $table->string('origin_country_code', 2)->default('US')->after('origin_postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['include_transit_times', 'origin_postal_code', 'origin_country_code']);
        });
    }
};
