<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_invoice_lines', function (Blueprint $table) {
            // Track shipping DB lookup status: null = not attempted, 'found', 'not_found'
            $table->string('shipping_lookup_status', 20)->nullable()->after('corrected_address_id');
            $table->timestamp('shipping_lookup_at')->nullable()->after('shipping_lookup_status');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['shipping_lookup_status', 'shipping_lookup_at']);
        });
    }
};
