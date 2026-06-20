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
            // How often the scheduler should check this mailbox, in minutes.
            // null/0 = manual only (use the "Fetch Now" button).
            $table->unsignedInteger('poll_minutes')->nullable()->after('is_active');
            $table->timestamp('last_processed_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('mail_integrations', function (Blueprint $table) {
            $table->dropColumn(['poll_minutes', 'last_processed_at']);
        });
    }
};
