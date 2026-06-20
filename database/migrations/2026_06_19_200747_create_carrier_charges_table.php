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
        Schema::create('carrier_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_invoice_id')->constrained('carrier_invoices')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->date('invoice_date')->nullable();
            $table->string('account_number')->nullable();
            $table->string('tracking_number')->nullable();

            // Raw values exactly as they appear on the invoice.
            $table->string('raw_charge_code')->nullable();
            $table->string('raw_charge_description')->nullable();

            // Normalized category (null = uncategorized, surfaced for mapping).
            $table->foreignId('charge_category_id')->nullable()->constrained('charge_categories')->nullOnDelete();

            $table->decimal('amount', 12, 2)->default(0);
            $table->string('service')->nullable();
            $table->string('zone', 20)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['carrier_id', 'invoice_date']);
            $table->index('charge_category_id');
            $table->index('tracking_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_charges');
    }
};
