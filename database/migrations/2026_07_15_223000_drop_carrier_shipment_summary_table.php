<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire carrier_shipment_summary. Its per-shipment cost view is now served directly by
 * carrier_shipments (base/fee split populated from charges for both carriers), so the
 * separate materialized table + ShipmentSummaryService are gone. down() recreates the
 * (empty) table for a clean rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('carrier_shipment_summary');
    }

    public function down(): void
    {
        Schema::create('carrier_shipment_summary', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_number');
            $table->date('invoice_date')->nullable();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedInteger('charge_count')->default(0);
            $table->string('fee_abbrevs')->nullable();
            $table->string('service')->nullable();
            $table->timestamps();

            $table->unique(['carrier_id', 'tracking_number', 'invoice_date'], 'shipment_summary_unique');
            $table->index(['carrier_id', 'invoice_date']);
            $table->index('tracking_number');
            $table->index('service');
        });
    }
};
