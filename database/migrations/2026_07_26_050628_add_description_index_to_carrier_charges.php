<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports re-resolution/backfill, which selects and bulk-updates charges keyed by
 * (carrier_id, raw_charge_description). Without this, every reclassification scanned all of a
 * carrier's charges per distinct description — turning a re-resolve into a full-table crawl.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->index(['carrier_id', 'raw_charge_description'], 'cc_carrier_desc_idx');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->dropIndex('cc_carrier_desc_idx');
        });
    }
};
