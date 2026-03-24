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
        Schema::create('carrier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();

            // File identification
            $table->string('filename');
            $table->string('original_path')->nullable();
            $table->string('archived_path')->nullable();
            $table->string('file_hash', 64)->unique(); // SHA256 to prevent re-processing

            // Invoice details
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('account_number')->nullable();

            // Processing stats
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('correction_records')->default(0);
            $table->unsignedInteger('new_corrections')->default(0);
            $table->unsignedInteger('duplicate_corrections')->default(0);
            $table->decimal('total_correction_charges', 10, 2)->default(0);

            // Status tracking
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('invoice_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_invoices');
    }
};
