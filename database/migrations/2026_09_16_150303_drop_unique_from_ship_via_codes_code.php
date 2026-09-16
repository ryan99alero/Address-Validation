<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The same "Your Code" intentionally maps to several ship-via options (sender vs third-party,
     * Ground vs Home Delivery, one code across plants) — so `code` must NOT be unique. The original
     * create migration made it unique; prod was already changed out-of-band to a plain index, so this
     * reconciles the migration set with prod idempotently: drop any unique index on `code`, then ensure
     * a plain lookup index remains. Safe on both a fresh DB (unique present) and prod (already plain).
     */
    public function up(): void
    {
        $unique = collect(Schema::getIndexes('ship_via_codes'))
            ->first(fn (array $i): bool => ($i['unique'] ?? false) && ($i['columns'] ?? []) === ['code']);

        if ($unique !== null) {
            Schema::table('ship_via_codes', fn (Blueprint $t) => $t->dropUnique($unique['name']));
        }

        $hasPlain = collect(Schema::getIndexes('ship_via_codes'))
            ->contains(fn (array $i): bool => ($i['columns'] ?? []) === ['code'] && ! ($i['unique'] ?? false));

        if (! $hasPlain) {
            Schema::table('ship_via_codes', fn (Blueprint $t) => $t->index('code'));
        }
    }

    /**
     * Not reversible: duplicate codes now exist by design, so a unique index can't be restored.
     */
    public function down(): void
    {
        // no-op
    }
};
