<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only event log of every re-correction: applied threads, human-review candidates, and
        // REJECTED garbage corrections (so "the carrier tried to send Houston to Arkansas and we refused"
        // is visible). Snapshots keep the history self-sufficient even if the address rows later change.
        Schema::create('address_supersessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('old_corrected_address_id')->nullable()->constrained('corrected_addresses')->nullOnDelete();
            $table->foreignId('new_corrected_address_id')->nullable()->constrained('corrected_addresses')->nullOnDelete();
            $table->json('old_snapshot')->nullable();
            $table->json('new_snapshot')->nullable();
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
            $table->foreignId('carrier_invoice_line_id')->nullable()->constrained('carrier_invoice_lines')->nullOnDelete();
            $table->string('trigger', 30);          // recorrection | variant_conflict | reverify_drift | manual | backfill
            $table->string('status', 20);           // applied | pending_review | rejected_garbage | dismissed
            $table->json('guard_result')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('old_corrected_address_id');
            $table->index('new_corrected_address_id');
            $table->index('status');
            $table->index(['old_corrected_address_id', 'new_corrected_address_id'], 'supersession_pair_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_supersessions');
    }
};
