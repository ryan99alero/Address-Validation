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
        Schema::table('addresses', function (Blueprint $table) {
            // Rename required_delivery_date to required_on_site_date
            $table->renameColumn('required_delivery_date', 'required_on_site_date');

            // Rename recommended_delivery_date to estimated_delivery_date
            $table->renameColumn('recommended_delivery_date', 'estimated_delivery_date');

            // Add new requested_ship_date field
            $table->date('requested_ship_date')->nullable()->after('ship_via_code_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->renameColumn('required_on_site_date', 'required_delivery_date');
            $table->renameColumn('estimated_delivery_date', 'recommended_delivery_date');
            $table->dropColumn('requested_ship_date');
        });
    }
};
