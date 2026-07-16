<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account owner — who a carrier billing account belongs to (your company, or a specific
 * client). BestWay's "don't ship on someone else's dime" rule is really about the OWNER,
 * not the exact account: your own accounts on a plant (e.g. Plant002's separate Ground and
 * Priority FedEx accounts) can be pooled into one service ladder, while a client's accounts
 * stay isolated. Null owner = untagged → falls back to the exact-account lock (safe default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_via_codes', function (Blueprint $table): void {
            $table->string('account_owner')->nullable()->after('account_number');
            $table->index(['plant_id', 'account_owner']);
        });
    }

    public function down(): void
    {
        Schema::table('ship_via_codes', function (Blueprint $table): void {
            $table->dropIndex(['plant_id', 'account_owner']);
            $table->dropColumn('account_owner');
        });
    }
};
