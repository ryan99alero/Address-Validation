<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table): void {
            // The actual invoice file this shipment was imported from (e.g. the PDF), so a
            // PDF-sourced shipment references the PDF even when the invoice's own filename is the CSV
            // (FedEx imports the CSV first, so invoice.filename is the CSV). source_type says csv|pdf;
            // source_file says WHICH file.
            $table->string('source_file')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table): void {
            $table->dropColumn('source_file');
        });
    }
};
