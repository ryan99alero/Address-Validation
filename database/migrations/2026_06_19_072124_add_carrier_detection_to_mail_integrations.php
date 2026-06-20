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
        Schema::table('mail_integrations', function (Blueprint $table) {
            // How to determine which carrier an email/attachment belongs to,
            // since one mailbox receives both UPS and FedEx invoices:
            // sender_domain, file_content, or fixed (use carrier_id).
            $table->string('carrier_detection', 20)->default('file_content')->after('carrier_id');
        });
    }

    public function down(): void
    {
        Schema::table('mail_integrations', function (Blueprint $table) {
            $table->dropColumn('carrier_detection');
        });
    }
};
