<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per carrier × tracking × invoice-date, rolling the ~3.4M charge lines
 * up into a per-shipment cost view (base, fees, total, the fee abbreviations we
 * got hit with, and — for UPS — the service level). Rebuilt from current data on
 * each invoice import, so the Per-Shipment Overview is an instant, filterable
 * list instead of a live GROUP BY over the charges table.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('carrier_shipment_summary');
    }
};
