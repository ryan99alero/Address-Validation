<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account owner — the party a carrier billing account belongs to, so BestWay can pool by
 * PAYER (never cross owners). Two types only: `company` (us) and `customer` (a client whose
 * account we ship on, e.g. for third-party billing). A structured row kills the free-text
 * drift ("Rand / rand / RAND / Rand Graphics") the stopgap ship_via_codes.account_owner had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('type')->default('customer'); // 'company' | 'customer'
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_owners');
    }
};
