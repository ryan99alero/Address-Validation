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
        Schema::table('carrier_invoices', function (Blueprint $table) {
            // How the invoice arrived: email, watch_folder, manual_upload, billing_center.
            $table->string('source', 30)->nullable()->after('carrier_id');
            // Origin detail to re-locate/re-process it later (mail integration+UID,
            // raw dump path + original filename, etc.).
            $table->string('source_reference')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_reference']);
        });
    }
};
