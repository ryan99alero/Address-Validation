<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverse scheduling: for an address with a required on-site date, the latest
     * ship date + cheapest service that still arrives on time (work backward from
     * the deadline rather than forward from a fixed ship date).
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->date('recommended_ship_date')->nullable()->after('requested_ship_date');
            $table->string('recommended_ship_service')->nullable()->after('recommended_ship_date');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['recommended_ship_date', 'recommended_ship_service']);
        });
    }
};
