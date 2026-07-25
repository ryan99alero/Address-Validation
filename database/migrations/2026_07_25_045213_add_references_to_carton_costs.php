<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carton_costs', function (Blueprint $table): void {
            // The three custom reference fields off the Pace JobShipment (Carton -> shipment/@U_reference).
            // U_reference2 typically mirrors the job number; U_reference is a PO/order-style ref.
            $table->string('U_reference', 100)->nullable()->after('pace_customer_id');
            $table->string('U_reference2', 100)->nullable()->after('U_reference');
            $table->string('U_reference3', 100)->nullable()->after('U_reference2');
        });
    }

    public function down(): void
    {
        Schema::table('carton_costs', function (Blueprint $table): void {
            $table->dropColumn(['U_reference', 'U_reference2', 'U_reference3']);
        });
    }
};
