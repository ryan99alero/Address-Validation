<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a specific correction mapping (input address → corrected address) be marked
 * unusable without deleting it — e.g. a dead customer address the carrier redirected
 * to our own address. Inactive variants are excluded from validation lookups, and
 * because dedup is by input_hash, a re-import of the same bad address stays flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_variants', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('input_hash');
            $table->string('inactive_reason')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('address_variants', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'inactive_reason']);
        });
    }
};
