<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stale UPS transit rows stored the old canonical service_type ("UPS_GROUND", "UPS_2ND_DAY_AIR",
 * "UPS_SERVICE_xx"…) that never matched a ship_via code's short code ("GND", "2DA"…). UPS transit
 * now emits the short codes; drop the old rows so they can't linger alongside the new ones and
 * skew fastest-service picks. Transit data is ephemeral — it re-fetches on the next batch.
 * "UPS%" targets only these (no FedEx type or UPS short code starts with "UPS").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transit_times')->where('service_type', 'like', 'UPS%')->delete();
    }

    public function down(): void
    {
        // Irreversible — the dropped rows are recomputed on demand.
    }
};
