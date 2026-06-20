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
            // Optional server-side IMAP search filters to target a carrier's
            // invoice emails and ignore everything else (spam, other carriers).
            $table->string('from_filter')->nullable()->after('attachment_pattern');
            $table->string('subject_filter')->nullable()->after('from_filter');
        });
    }

    public function down(): void
    {
        Schema::table('mail_integrations', function (Blueprint $table) {
            $table->dropColumn(['from_filter', 'subject_filter']);
        });
    }
};
