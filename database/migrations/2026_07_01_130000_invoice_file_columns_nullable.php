<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CarrierInvoice is now one real invoice number, not a source file, so the
 * file-provenance columns no longer apply to every invoice — make them nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_invoices', function (Blueprint $table): void {
            $table->string('filename', 255)->nullable()->change();
            $table->string('source_reference', 255)->nullable()->change();
            $table->string('original_path', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // left nullable — no safe non-null backfill
    }
};
