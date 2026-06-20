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
        Schema::create('folder_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();

            // local = an OS-mounted/local path (no app credentials needed).
            // smb   = connect to a Windows share directly (host/share/credentials).
            $table->string('connection_type', 10)->default('local');

            // For local: the absolute mounted path. For smb: the path within the share.
            $table->string('base_path');

            // SMB-only connection details (credentials stored encrypted).
            $table->string('smb_host')->nullable();
            $table->string('smb_share')->nullable();
            $table->text('credentials')->nullable();

            $table->boolean('recursive')->default(true);
            $table->boolean('prefer_csv')->default(true);
            $table->string('file_pattern')->default('*.csv,*.pdf');

            $table->unsignedInteger('poll_minutes')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folder_integrations');
    }
};
