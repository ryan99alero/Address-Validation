<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quarantine support. The txn_id hash blocks EXACT duplicates, but a re-import that corrects a charge's
 * amount or recategorizes it (e.g. a fee moved from Base Transportation to Address Correction) yields a
 * genuinely different hash — it would post a second JobCost for the same shipment. Those near-duplicates
 * are held as `quarantined`, pointing at the already-posted counterpart (conflict_with_id) with the
 * reason, for a human to Push or Dismiss. Review audit fields record who decided and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->unsignedBigInteger('conflict_with_id')->nullable()->after('duplicate_of_id');
            $table->string('conflict_reason', 24)->nullable()->after('conflict_with_id');
            $table->unsignedBigInteger('reviewed_by_id')->nullable()->after('reversal_state');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');
            $table->index('conflict_with_id');
        });
    }

    public function down(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->dropIndex(['conflict_with_id']);
            $table->dropColumn(['conflict_with_id', 'conflict_reason', 'reviewed_by_id', 'reviewed_at', 'review_note']);
        });
    }
};
