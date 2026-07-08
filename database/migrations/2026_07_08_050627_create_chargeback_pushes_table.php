<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chargeback ledger — one row per carrier charge pushed (or considered) as a Pace JobCost.
 * It is a financial record: it must outlive the charge row (charges get deleted/recreated on
 * PDF re-import), so `carrier_charge_id` is a nullable, null-on-delete convenience link and the
 * real identity is `dedupe_key` (natural key), UNIQUE, which the claim-first insert uses as the
 * mutex that guarantees a charge is never double-pushed / double-billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargeback_pushes', function (Blueprint $table): void {
            $table->id();

            // Natural identity — survives charge delete/recreate on re-import.
            $table->string('dedupe_key')->unique();
            $table->foreignId('carrier_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('carrier_id')->nullable();
            $table->unsignedBigInteger('carrier_invoice_id')->nullable();
            $table->string('tracking_number')->nullable();
            $table->unsignedBigInteger('charge_category_id')->nullable();
            $table->string('driver', 40)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('ship_date')->nullable();

            // Snapshot of what we pushed (never re-derived from the charge, which can change).
            $table->string('pace_job')->nullable();
            $table->string('pace_job_part')->nullable();
            $table->string('pace_customer_id')->nullable();
            $table->string('activity_code')->nullable();
            $table->text('notes')->nullable();
            $table->string('pace_jobcost_id')->nullable(); // the created record's PK — the trust column

            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('pushed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'carrier_id']);
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargeback_pushes');
    }
};
