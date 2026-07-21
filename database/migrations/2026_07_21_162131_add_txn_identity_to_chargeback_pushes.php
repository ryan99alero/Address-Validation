<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stable chargeback identity. `dedupe_key` included ship_date, which flips null->date on invoice
 * re-import and split one charge into two ledger rows (14 JobCosts double-posted). The new `txn_id` is
 * a deterministic content hash keyed on invoice identity instead of ship_date, so a re-import can never
 * fork it. Duplicates found during backfill point at their canonical row via `duplicate_of_id` and are
 * marked `reversal_state = needs_reversal`. `txn_id` is nullable-unique: canonical rows carry it,
 * flagged duplicates keep it null (many nulls are allowed in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->string('txn_id', 64)->nullable()->unique()->after('id');
            $table->unsignedTinyInteger('identity_version')->default(1)->after('txn_id');
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->after('dedupe_key');
            $table->string('reversal_state', 24)->nullable()->after('status');
            $table->index('duplicate_of_id');
            $table->index('reversal_state');
        });
    }

    public function down(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->dropUnique(['txn_id']);
            $table->dropIndex(['duplicate_of_id']);
            $table->dropIndex(['reversal_state']);
            $table->dropColumn(['txn_id', 'identity_version', 'duplicate_of_id', 'reversal_state']);
        });
    }
};
