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
        Schema::create('mail_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);

            // Which carrier these invoices belong to (also used as the archive folder name).
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();

            // IMAP connection (non-secret parts).
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl'); // ssl, tls, starttls, none
            $table->boolean('imap_validate_cert')->default(true);
            $table->string('imap_username');
            $table->string('imap_folder')->default('INBOX');

            // Where to move emails after successful processing (null = leave in place, mark seen).
            $table->string('processed_folder')->nullable();

            // Glob for attachments to pull (UPS = password-protected .zip of PDFs).
            $table->string('attachment_pattern')->default('*.zip');

            // Encrypted JSON: imap_password, zip_password (static ZIP password).
            $table->text('credentials')->nullable();

            // Archive destination for the Carrier/Year/Month file scheme.
            $table->string('archive_disk')->default('local');
            $table->string('archive_base_path')->default('invoices/processed');

            // Polling status/diagnostics.
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status', 20)->nullable(); // ok, error
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_integrations');
    }
};
