<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The true identity of a carrier invoice is (carrier, number, date). Keying on
     * number alone merged invoices that share a recycled number a decade apart
     * (UPS reuses the E540W### series ~every 10 years). This unique index enforces
     * the corrected identity so the regression can never silently recur.
     */
    public function up(): void
    {
        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->unique(['carrier_id', 'invoice_number', 'invoice_date'], 'carrier_invoices_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->dropUnique('carrier_invoices_identity_unique');
        });
    }
};
