<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tracking number per invoice, parsed from UPS PDF invoices. Holds the
     * shipment-level attributes charges alone can't (dimensions, UPS-audited dimensions,
     * weights, message codes) so DIM re-rate disputes can be surfaced.
     */
    public function up(): void
    {
        Schema::create('carrier_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_invoice_id')->constrained('carrier_invoices')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->string('tracking_number')->nullable();
            $table->string('section', 40)->nullable();
            $table->string('service')->nullable();
            $table->string('zip', 16)->nullable();
            $table->string('zone', 8)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('billed_weight', 10, 2)->nullable();
            $table->date('ship_date')->nullable();
            $table->string('customer_dims')->nullable();
            $table->string('audited_dims')->nullable();
            $table->decimal('customer_weight', 10, 2)->nullable();
            $table->json('message_codes')->nullable();
            $table->text('sender')->nullable();
            $table->text('receiver')->nullable();
            $table->text('third_party')->nullable();
            $table->boolean('is_third_party')->default(false);
            $table->decimal('printed_total', 12, 2)->nullable();
            $table->string('source_type', 8)->nullable();
            $table->timestamps();

            $table->index('carrier_invoice_id');
            $table->index('tracking_number');
            $table->index(['carrier_id', 'audited_dims']);
            $table->index(['carrier_id', 'is_third_party']);
        });

        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->foreignId('carrier_shipment_id')->nullable()->after('carrier_invoice_id')
                ->constrained('carrier_shipments')->nullOnDelete();
        });

        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->decimal('charges_parsed_total', 12, 2)->nullable();
            $table->decimal('charges_expected_total', 12, 2)->nullable();
            $table->boolean('charges_reconciled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_shipment_id');
        });

        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->dropColumn(['charges_parsed_total', 'charges_expected_total', 'charges_reconciled']);
        });

        Schema::dropIfExists('carrier_shipments');
    }
};
