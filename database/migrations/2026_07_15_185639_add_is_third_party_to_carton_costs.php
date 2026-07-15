<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Authoritative third-party billing flag from Pace (JobShipment/@thirdPartyCharges),
     * mirrored per carton so charges can be classified locally by tracking number.
     * Null = Pace didn't resolve it → fall back to the base-charge heuristic.
     */
    public function up(): void
    {
        Schema::table('carton_costs', function (Blueprint $table) {
            $table->boolean('is_third_party')->nullable()->after('pace_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('carton_costs', function (Blueprint $table) {
            $table->dropColumn('is_third_party');
        });
    }
};
