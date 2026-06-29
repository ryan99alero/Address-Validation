<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the per-shipment cost summary from carrier_charges: one row per
 * carrier × tracking × invoice-date with base, fees, total, the fee-category
 * abbreviations, and (UPS only) the service level parsed from the base line's
 * description. Full re-derive, so adds and deletes are both reflected.
 */
class ShipmentSummaryService
{
    /**
     * UPS carries the service in the Base Transportation description; FedEx's base
     * line is just "Transportation", so it resolves to NULL.
     */
    private const SERVICE_CASE = "
        CASE
            WHEN cc.raw_charge_description LIKE 'Next Day Air Saver%' THEN 'Next Day Air Saver'
            WHEN cc.raw_charge_description LIKE 'Next Day Air%'       THEN 'Next Day Air'
            WHEN cc.raw_charge_description LIKE '2nd Day Air%'        THEN '2nd Day Air'
            WHEN cc.raw_charge_description LIKE '3 Day Select%'       THEN '3 Day Select'
            WHEN cc.raw_charge_description LIKE 'Ground%'             THEN 'Ground'
            ELSE NULL
        END
    ";

    public function rebuild(): void
    {
        DB::statement('SET SESSION group_concat_max_len = 8192');

        DB::transaction(function (): void {
            DB::table('carrier_shipment_summary')->delete();
            DB::statement('
                INSERT INTO carrier_shipment_summary
                    (carrier_id, tracking_number, invoice_date, base_amount, fee_amount, total_amount, charge_count, fee_abbrevs, service, created_at, updated_at)
                SELECT
                    cc.carrier_id,
                    cc.tracking_number,
                    cc.invoice_date,
                    SUM(CASE WHEN cat.name = "Base Transportation" THEN cc.amount ELSE 0 END),
                    SUM(CASE WHEN cat.name IN ("Base Transportation", "Discount / Credit") THEN 0 ELSE cc.amount END),
                    SUM(cc.amount),
                    COUNT(*),
                    GROUP_CONCAT(DISTINCT CASE WHEN cat.name NOT IN ("Base Transportation", "Discount / Credit") THEN cat.abbreviation END ORDER BY cat.abbreviation SEPARATOR ", "),
                    MAX(CASE WHEN cat.name = "Base Transportation" THEN '.self::SERVICE_CASE.' END),
                    NOW(), NOW()
                FROM carrier_charges cc
                LEFT JOIN charge_categories cat ON cat.id = cc.charge_category_id
                WHERE cc.tracking_number IS NOT NULL AND cc.tracking_number <> ""
                GROUP BY cc.carrier_id, cc.tracking_number, cc.invoice_date
            ');
        });
    }
}
