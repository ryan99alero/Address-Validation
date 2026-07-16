<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A carrier billing account — ours or a customer's — as a first-class row instead of a
 * free-text number scattered on ship-via codes. One owner can hold several (e.g. Plant002's
 * separate FedEx Ground and Priority accounts). account_owner_id is nullable only for the
 * backfill window: an untagged account must lock to itself, never be guessed as ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_owner_id')->nullable()->constrained('account_owners')->nullOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->string('account_number');
            $table->string('nickname'); // human handle — account numbers are opaque
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['carrier_id', 'account_number']);
            $table->index('account_owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_accounts');
    }
};
