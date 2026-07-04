<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records why a file produced no invoices — e.g. 'legacy_format' for pre-~2016 Ricoh
     * UPS PDFs that are intentionally skipped — so they're easy to find and re-run later.
     */
    public function up(): void
    {
        Schema::table('carrier_import_files', function (Blueprint $table) {
            $table->string('skip_reason')->nullable()->after('invoice_count');
            $table->index('skip_reason');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_import_files', function (Blueprint $table) {
            $table->dropIndex(['skip_reason']);
            $table->dropColumn('skip_reason');
        });
    }
};
