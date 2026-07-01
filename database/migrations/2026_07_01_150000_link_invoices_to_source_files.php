<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link real invoices back to the batch file(s) they were imported from, so a user
 * can find and download the original CSV/PDF for any invoice (a file holds many
 * invoices, and an invoice can come from both a CSV and a PDF — hence a pivot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_import_files', function (Blueprint $table): void {
            $table->foreignId('folder_integration_id')->nullable()->after('carrier_id')->constrained()->nullOnDelete();
        });

        Schema::create('carrier_import_file_invoice', function (Blueprint $table): void {
            $table->foreignId('carrier_import_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_invoice_id')->constrained()->cascadeOnDelete();
            $table->primary(['carrier_import_file_id', 'carrier_invoice_id'], 'import_file_invoice_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_import_file_invoice');

        Schema::table('carrier_import_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('folder_integration_id');
        });
    }
};
