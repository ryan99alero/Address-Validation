<?php

namespace App\Services\Invoices;

use App\Models\CarrierInvoice;
use App\Models\ChargeCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Populates per-shipment cost data on carrier_shipments from the invoice's charges.
 *
 *  - deriveForInvoice()  — FedEx has no printed per-shipment section, so we CREATE one
 *    carrier_shipments row per tracking (total, service, weight, zone, base/fee split,
 *    billing type). Rows are tagged source_type='derived' so UPS PDF-extracted rows are
 *    never touched; they carry carrier_invoice_id and cascade-delete with the invoice.
 *  - enrichCostsForInvoice() — UPS shipments already exist from the PDF; UPDATE them with
 *    the base/fee split from charges so the same columns are populated for both carriers.
 */
class FedExShipmentDeriveService
{
    public const SOURCE = 'derived';

    /**
     * (Re)create derived per-shipment rows for a FedEx invoice. Idempotent.
     */
    public function deriveForInvoice(CarrierInvoice $invoice): int
    {
        DB::table('carrier_shipments')
            ->where('carrier_invoice_id', $invoice->id)
            ->where('source_type', self::SOURCE)
            ->delete();

        $now = now();
        $rows = $this->aggregateByTracking($invoice->id)
            ->map(fn ($r): array => [
                'carrier_invoice_id' => $invoice->id,
                'carrier_id' => $invoice->carrier_id,
                'tracking_number' => $r->tracking_number,
                'service' => $r->service,
                'zone' => $r->zone,
                'billed_weight' => $r->billed_weight,
                'ship_date' => $r->ship_date,
                'printed_total' => $r->printed_total,
                'base_amount' => $r->base_amount,
                'fee_amount' => $r->fee_amount,
                'fee_abbrevs' => $r->fee_abbrevs,
                'is_third_party' => $r->base_count > 0 ? 0 : 1,
                'source_type' => self::SOURCE,
                // Synthesized from this invoice's charges — the invoice's own file is the best source
                // provenance for a derived row (keeps every shipment-creation path stamped).
                'source_file' => $invoice->filename,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('carrier_shipments')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Fill the per-shipment cost (total + base/fee split) on an invoice's EXISTING
     * (non-derived, i.e. UPS PDF) shipments from its charges. Matched by
     * carrier_shipment_id — NOT tracking — because a single UPS tracking spans several
     * shipment rows (outbound + address-correction + shipping-charge-correction sections);
     * keying by tracking would give every row the tracking's full total and triple-count.
     * printed_total is set to the shipment's actual charge sum so the Per-Shipment Costs
     * view reconciles to the invoice's charges (the PDF's printed Total is null for most
     * blocks). Returns rows updated.
     */
    public function enrichCostsForInvoice(CarrierInvoice $invoice): int
    {
        $agg = $this->aggregateByShipment($invoice->id)->keyBy('carrier_shipment_id');
        if ($agg->isEmpty()) {
            return 0;
        }

        // Reset cost on every non-derived shipment first. A UPS tracking spans several rows
        // (outbound + correction sections); when charges are CSV-sourced their whole cost is
        // attributed to the tracking's primary row, so the sibling rows must drop any stale
        // printed_total or the tracking would double-count.
        $invoice->shipments()
            ->where('source_type', '!=', self::SOURCE)
            ->update(['printed_total' => 0, 'base_amount' => 0, 'fee_amount' => 0, 'fee_abbrevs' => null]);

        $updated = 0;
        $invoice->shipments()
            ->where('source_type', '!=', self::SOURCE)
            ->chunkById(500, function ($shipments) use ($agg, &$updated): void {
                foreach ($shipments as $shipment) {
                    $a = $agg->get($shipment->id);
                    if (! $a) {
                        continue;
                    }
                    $shipment->forceFill([
                        'printed_total' => $a->total_cost,
                        'base_amount' => $a->base_amount,
                        'fee_amount' => $a->fee_amount,
                        'fee_abbrevs' => $a->fee_abbrevs,
                    ])->save();
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Aggregate an invoice's charges into one row per carrier_shipment_id (the precise
     * per-shipment grain). total_cost is every charge on the shipment; base/fee split it.
     *
     * @return Collection<int, object>
     */
    protected function aggregateByShipment(int $invoiceId): Collection
    {
        $baseCategoryId = (int) (ChargeCategory::query()->where('name', 'Base Transportation')->value('id') ?? 0);
        $discountCategoryId = (int) (ChargeCategory::query()->where('name', 'Discount / Credit')->value('id') ?? 0);

        return DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as cat', 'cat.id', '=', 'cc.charge_category_id')
            ->where('cc.carrier_invoice_id', $invoiceId)
            ->whereNotNull('cc.carrier_shipment_id')
            ->groupBy('cc.carrier_shipment_id')
            ->selectRaw('cc.carrier_shipment_id')
            ->selectRaw('SUM(cc.amount) as total_cost')
            ->selectRaw('SUM(CASE WHEN cc.charge_category_id = ? THEN cc.amount ELSE 0 END) as base_amount', [$baseCategoryId])
            ->selectRaw('SUM(CASE WHEN cc.charge_category_id IN (?, ?) THEN 0 ELSE cc.amount END) as fee_amount', [$baseCategoryId, $discountCategoryId])
            ->selectRaw('GROUP_CONCAT(DISTINCT CASE WHEN cc.charge_category_id NOT IN (?, ?) THEN cat.abbreviation END) as fee_abbrevs', [$baseCategoryId, $discountCategoryId])
            ->get();
    }

    /**
     * Aggregate an invoice's charges into one row per tracking number.
     *
     * @return Collection<int, object>
     */
    protected function aggregateByTracking(int $invoiceId): Collection
    {
        $baseCategoryId = (int) (ChargeCategory::query()->where('name', 'Base Transportation')->value('id') ?? 0);
        $discountCategoryId = (int) (ChargeCategory::query()->where('name', 'Discount / Credit')->value('id') ?? 0);

        return DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as cat', 'cat.id', '=', 'cc.charge_category_id')
            ->where('cc.carrier_invoice_id', $invoiceId)
            ->whereNotNull('cc.tracking_number')
            ->where('cc.tracking_number', '<>', '')
            ->groupBy('cc.tracking_number')
            ->selectRaw('cc.tracking_number')
            ->selectRaw('MAX(cc.service) as service')
            ->selectRaw('MAX(cc.zone) as zone')
            ->selectRaw('MAX(cc.weight) as billed_weight')
            ->selectRaw('MAX(cc.ship_date) as ship_date')
            ->selectRaw('SUM(cc.amount) as printed_total')
            ->selectRaw('SUM(CASE WHEN cc.charge_category_id = ? THEN cc.amount ELSE 0 END) as base_amount', [$baseCategoryId])
            ->selectRaw('SUM(CASE WHEN cc.charge_category_id IN (?, ?) THEN 0 ELSE cc.amount END) as fee_amount', [$baseCategoryId, $discountCategoryId])
            ->selectRaw('GROUP_CONCAT(DISTINCT CASE WHEN cc.charge_category_id NOT IN (?, ?) THEN cat.abbreviation END) as fee_abbrevs', [$baseCategoryId, $discountCategoryId])
            // Heuristic: a tracking with no Base Transportation charge is third-party.
            ->selectRaw('SUM(CASE WHEN cc.charge_category_id = ? THEN 1 ELSE 0 END) as base_count', [$baseCategoryId])
            ->get();
    }
}
