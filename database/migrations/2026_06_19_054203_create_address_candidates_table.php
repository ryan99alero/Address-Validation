<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate corrected addresses for an address awaiting review.
     *
     * When "check both" validation runs, the invoice-DB lookup and the carrier
     * API can each produce a correction. Each is stored here so a user can pick
     * which one becomes the address's final output (the chosen candidate is
     * copied into addresses.output_* and the rest are purged).
     */
    public function up(): void
    {
        Schema::create('address_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();

            // Where this candidate came from: invoice_db, fedex_api, ups_api, usps_api, manual
            $table->string('source', 20);

            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 50)->nullable();
            $table->string('postal', 20)->nullable();
            $table->string('postal_ext', 10)->nullable();
            $table->string('country', 2)->nullable();

            $table->boolean('is_residential')->nullable();
            $table->string('classification', 20)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();

            // Provenance: the carrier that produced an API candidate, or the
            // corrected_addresses row that produced an invoice-DB candidate.
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
            $table->foreignId('corrected_address_id')->nullable()->constrained('corrected_addresses')->nullOnDelete();

            $table->timestamps();

            $table->index(['address_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_candidates');
    }
};
