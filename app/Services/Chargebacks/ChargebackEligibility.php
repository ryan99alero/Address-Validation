<?php

namespace App\Services\Chargebacks;

use App\Models\ChargebackPush;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which charges on a set of invoices are eligible for a customer chargeback, and resolves
 * each one's Pace activity code. A charge qualifies when its Chargeback Code (driver) is flagged to
 * push AND has a cost center, its Fee Category is set, it has a tracking, a positive amount, its
 * invoice is recent + reconciled, and it isn't already in the ledger. activityCode = the category's
 * cost center, falling back to the driver's — except fuel, which splits by what it rode in on (see
 * FUEL_CATEGORY_NAME below).
 */
class ChargebackEligibility
{
    /**
     * Name of the Fee Category that holds fuel surcharges. Fuel is special: a fuel charge inherits the
     * driver of the correction it rode in on — attributeCorrectionSurcharges() stamps an audit-only
     * tracking's fuel with driver=audit_correction, an address-only tracking's with
     * address_correction. So the SAME fuel category can book to different Pace cost centers by driver,
     * a split one category cost center can't express. The per-driver override is editable in the UI at
     * Chargeback Codes (charge_drivers.fuel_cost_center): when a fuel charge's driver has a
     * fuel_cost_center it wins; otherwise the fuel category's own cost center (its default) applies.
     * Blank on every driver => all fuel books to that one default.
     */
    private const FUEL_CATEGORY_NAME = 'Fuel Surcharge';

    /**
     * @param  array<int, int>  $invoiceIds
     * @return Collection<int, object{carrier_charge_id:int, carrier_id:int, carrier_invoice_id:int, invoice_number:?string, invoice_date:?string, tracking_number:string, charge_category_id:int, driver:string, amount:float, ship_date:?string, activity_code:string}>
     */
    public function forInvoices(array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        return $this->baseQuery()
            ->whereIn('cc.carrier_invoice_id', $invoiceIds)
            ->get()
            ->reject(fn (object $r): bool => $this->alreadyPushed($r))
            ->map(fn (object $r): object => $this->castRow($r))
            ->values();
    }

    /**
     * The same eligible charges, scoped to specific carrier_charge ids — for RE-DRIVING charges that
     * are already in the ledger (e.g. rows falsely skipped by a resolver bug). It deliberately does
     * NOT reject already-pushed rows: the caller resets those rows to `pending` first and wants them
     * re-dispatched. Every other eligibility rule still applies, so a charge that has since become
     * stale (unreconciled, too old) is simply not returned and its skip is left untouched.
     *
     * @param  array<int, int>  $chargeIds
     * @return Collection<int, object{carrier_charge_id:int, carrier_id:int, carrier_invoice_id:int, invoice_number:?string, invoice_date:?string, tracking_number:string, charge_category_id:int, driver:string, amount:float, ship_date:?string, activity_code:string}>
     */
    public function forChargeIds(array $chargeIds): Collection
    {
        $chargeIds = array_values(array_unique(array_filter($chargeIds)));
        if ($chargeIds === []) {
            return collect();
        }

        return $this->baseQuery()
            ->whereIn('cc.id', $chargeIds)
            ->get()
            ->map(fn (object $r): object => $this->castRow($r))
            ->values();
    }

    /**
     * Shared eligibility query: the columns + rules that define a chargeable charge. Callers add the
     * scope (by invoice or by charge id) and decide whether to reject ledger duplicates.
     */
    private function baseQuery(): Builder
    {
        $fuelCategoryId = (int) (DB::table('charge_categories')->where('name', self::FUEL_CATEGORY_NAME)->value('id') ?? 0);

        return DB::table('carrier_charges as cc')
            ->join('charge_drivers as d', 'd.key', '=', 'cc.driver')
            ->join('charge_categories as c', 'c.id', '=', 'cc.charge_category_id')
            ->join('carrier_invoices as i', 'i.id', '=', 'cc.carrier_invoice_id')
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
                CASE
                    WHEN cc.charge_category_id = ? AND d.fuel_cost_center IS NOT NULL AND d.fuel_cost_center <> ?
                        THEN d.fuel_cost_center
                    ELSE COALESCE(NULLIF(c.pace_cost_center, ?), d.pace_activity_code)
                END AS activity_code
            ', [$fuelCategoryId, '', '']);
    }

    private function castRow(object $r): object
    {
        $r->amount = (float) $r->amount;

        return $r;
    }

    /**
     * Already in the ledger (any disposition) for this charge's natural identity — don't re-enqueue.
     */
    private function alreadyPushed(object $r): bool
    {
        // txn_id is the identity going forward (stable across a re-import that changes ship_date). The
        // legacy dedupe_key is a transition fallback that also catches rows not yet backfilled; it can
        // be dropped once every environment has run chargebacks:backfill-identity.
        $txnId = ChargebackPush::identity((array) $r);
        $legacy = ChargebackPush::dedupeKey((int) $r->carrier_id, $r->tracking_number, (int) $r->charge_category_id, $r->amount, $r->ship_date);

        return ChargebackPush::where('txn_id', $txnId)->orWhere('dedupe_key', $legacy)->exists();
    }
}
