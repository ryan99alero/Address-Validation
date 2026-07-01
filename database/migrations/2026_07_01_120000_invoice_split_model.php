<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move to "one CarrierInvoice per real invoice number" (batch files hold several):
 * - charges carry their own per-shipment ship_date (not the invoice date smeared over all)
 * - invoices are matched by (carrier_id, invoice_number) — so file_hash is no longer the
 *   invoice identity; file-level "already processed" tracking moves to carrier_import_files
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table): void {
            $table->date('ship_date')->nullable()->after('invoice_date');
        });

        Schema::table('carrier_invoices', function (Blueprint $table): void {
            $table->string('file_hash', 64)->nullable()->change();
            $table->index(['carrier_id', 'invoice_number'], 'carrier_invoices_carrier_invnum_idx');
        });

        Schema::create('carrier_import_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_hash', 64)->unique();
            $table->string('filename')->nullable();
            $table->string('source_reference')->nullable();
            $table->unsignedInteger('invoice_count')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_import_files');

        Schema::table('carrier_invoices', function (Blueprint $table): void {
            $table->dropIndex('carrier_invoices_carrier_invnum_idx');
        });

        Schema::table('carrier_charges', function (Blueprint $table): void {
            $table->dropColumn('ship_date');
        });
    }
};
