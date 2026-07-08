<?php

namespace App\Services\Chargebacks;

use App\Models\ChargebackPush;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which charges on a set of invoices are eligible for a customer chargeback, and resolves
 * each one's Pace activity code. A charge qualifies when its Chargeback Code (driver) is flagged to
 * push AND has a cost center, its Fee Category is set, it has a tracking, a positive amount, its
 * invoice is recent + reconciled, and it isn't already in the ledger. activityCode = the category's
 * cost center, falling back to the driver's.
 */
class ChargebackEligibility
{
    /**
     * @param  array<int, int>  $invoiceIds
     * @return Collection<int, object{carrier_charge_id:int, carrier_id:int, carrier_invoice_id:int, invoice_number:?string, invoice_date:?string, tracking_number:string, charge_category_id:int, driver:string, amount:float, ship_date:?string, activity_code:string}>
     */
    public function forInvoices(array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        return DB::table('carrier_charges as cc')
            ->join('charge_drivers as d', 'd.key', '=', 'cc.driver')
            ->join('charge_categories as c', 'c.id', '=', 'cc.charge_category_id')
            ->join('carrier_invoices as i', 'i.id', '=', 'cc.carrier_invoice_id')
            ->whereIn('cc.carrier_invoice_id', $invoiceIds)
            ->where('d.push_to_pace', true)
            ->whereNotNull('d.pace_activity_code')->where('d.pace_activity_code', '!=', '')
            ->whereNotNull('cc.tracking_number')->where('cc.tracking_number', '!=', '')
            ->where('cc.amount', '>', 0)
            ->where('i.invoice_date', '>=', CartonCostSyncService::recentInvoiceCutoff())
            ->where('i.charges_reconciled', true)
            ->selectRaw('
                cc.id AS carrier_charge_id, cc.carrier_id, cc.carrier_invoice_id,
                i.invoice_number, i.invoice_date, cc.tracking_number, cc.charge_category_id,
                cc.driver, cc.amount, cc.ship_date,
                COALESCE(NULLIF(c.pace_cost_center, ?), d.pace_activity_code) AS activity_code
            ', [''])
            ->get()
            ->reject(fn (object $r): bool => $this->alreadyPushed($r))
            ->map(function (object $r): object {
                $r->amount = (float) $r->amount;

                return $r;
            })
            ->values();
    }

    /**
     * Already in the ledger (any disposition) for this charge's natural identity — don't re-enqueue.
     */
    private function alreadyPushed(object $r): bool
    {
        $key = ChargebackPush::dedupeKey(
            (int) $r->carrier_id, $r->tracking_number, (int) $r->charge_category_id, $r->amount, $r->ship_date,
        );

        return ChargebackPush::where('dedupe_key', $key)->exists();
    }
}
