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
            // IMAP command sequence mode: 'uid' (standard, default) or 'msgn'
            // (message numbers). Auto-switched to 'msgn' if a server rejects UID
            // commands (e.g. Zimbra: "command not permitted with UID").
            $table->string('imap_sequence', 8)->default('uid')->after('imap_folder');
        });
    }

    public function down(): void
    {
        Schema::table('mail_integrations', function (Blueprint $table) {
            $table->dropColumn('imap_sequence');
        });
    }
};
