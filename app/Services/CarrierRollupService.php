<?php

namespace App\Services;

use App\Models\CarrierChargeRollup;
use App\Models\CarrierShipRollup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the carrier reporting rollups from the raw carrier_charges table.
 *
 * The rebuild is a full re-aggregation wrapped in a transaction, so other
 * sessions see the previous rollup until commit (atomic swap via MVCC) and a
 * failure leaves the old data intact. Because it re-derives from scratch, both
 * added and deleted charges are reflected with no incremental/reversal logic.
 */
class CarrierRollupService
{
    private const AUX_SHIP = "(cat.name IS NULL OR cat.name NOT IN ('Base Transportation', 'Discount / Credit')) AND cc.amount > 0";

    public function rebuild(): void
    {
        DB::transaction(function (): void {
            DB::table('carrier_charge_rollup')->delete();
            DB::statement('
                INSERT INTO carrier_charge_rollup
                    (carrier_id, charge_category_id, year, charge_count, total_amount, distinct_ships, created_at, updated_at)
                SELECT carrier_id, charge_category_id, YEAR(invoice_date), COUNT(*),
                       COALESCE(SUM(amount), 0), COUNT(DISTINCT tracking_number), NOW(), NOW()
                FROM carrier_charges
                WHERE invoice_date IS NOT NULL
                GROUP BY carrier_id, charge_category_id, YEAR(invoice_date)
            ');

            DB::table('carrier_ship_rollup')->delete();
            DB::statement('
                INSERT INTO carrier_ship_rollup
                    (carrier_id, year, total_ships, aux_ships, created_at, updated_at)
                SELECT cc.carrier_id, YEAR(cc.invoice_date),
                       COUNT(DISTINCT cc.tracking_number),
                       COUNT(DISTINCT CASE WHEN '.self::AUX_SHIP.' THEN cc.tracking_number END),
                       NOW(), NOW()
                FROM carrier_charges cc
                LEFT JOIN charge_categories cat ON cat.id = cc.charge_category_id
                WHERE cc.invoice_date IS NOT NULL
                GROUP BY cc.carrier_id, YEAR(cc.invoice_date)
            ');
        });
    }

    /**
     * When the rollup was last rebuilt (newest row), for a "current as of" label.
     */
    public function lastBuiltAt(): ?Carbon
    {
        $value = CarrierChargeRollup::max('updated_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function isEmpty(): bool
    {
        return ! CarrierChargeRollup::query()->exists();
    }
}
