<?php

namespace App\Services;

use App\Models\CarrierChargeRollup;
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
        // Base Transportation category drives the third-party heuristic (a tracking
        // with no base charge is third-party). 0 = "no such category" so the
        // heuristic simply never matches a base charge.
        $baseCategoryId = (int) (DB::table('charge_categories')->where('name', 'Base Transportation')->value('id') ?? 0);

        DB::transaction(function () use ($baseCategoryId): void {
            DB::table('carrier_charge_rollup')->delete();
            // Per-tracking billing type resolved set-based (Pace flag first, else the
            // base-charge heuristic), then the rollup groups by it. NULL tracking
            // (account-level fees) stays unclassified (is_third_party NULL).
            DB::statement("
                INSERT INTO carrier_charge_rollup
                    (carrier_id, charge_category_id, is_third_party, year, charge_count, total_amount, distinct_ships, created_at, updated_at)
                SELECT cc.carrier_id, cc.charge_category_id, tp.is_third_party, YEAR(cc.invoice_date), COUNT(*),
                       COALESCE(SUM(cc.amount), 0), COUNT(DISTINCT cc.tracking_number), NOW(), NOW()
                FROM carrier_charges cc
                LEFT JOIN (
                    SELECT t.tracking_number,
                           CASE
                               WHEN k.is_third_party IS NOT NULL THEN k.is_third_party
                               WHEN b.tracking_number IS NOT NULL THEN 0
                               ELSE 1
                           END AS is_third_party
                    FROM (SELECT DISTINCT tracking_number FROM carrier_charges WHERE tracking_number IS NOT NULL AND tracking_number <> '') t
                    LEFT JOIN carton_costs k ON k.tracking_number = t.tracking_number
                    LEFT JOIN (SELECT DISTINCT tracking_number FROM carrier_charges WHERE charge_category_id = {$baseCategoryId}) b
                        ON b.tracking_number = t.tracking_number
                ) tp ON tp.tracking_number = cc.tracking_number
                WHERE cc.invoice_date IS NOT NULL
                GROUP BY cc.carrier_id, cc.charge_category_id, tp.is_third_party, YEAR(cc.invoice_date)
            ");

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
