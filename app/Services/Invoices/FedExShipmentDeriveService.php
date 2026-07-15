<?php

namespace App\Services\Invoices;

use App\Models\CarrierInvoice;
use App\Models\ChargeCategory;
use Illuminate\Support\Facades\DB;

/**
 * FedEx invoices carry no printed per-shipment section (unlike UPS PDFs), so no
 * carrier_shipments rows are extracted at parse time and the Per-Shipment Costs
 * view renders empty. This derives one carrier_shipments row per tracking by
 * aggregating the invoice's own charges — total, service, weight, zone, and
 * billing type — mirroring what UPS gets from the PDF.
 *
 * Only ever writes/removes rows for THIS invoice tagged source_type='derived',
 * so UPS PDF-extracted shipments are never touched. Rows carry carrier_invoice_id
 * and cascade-delete with the invoice.
 */
class FedExShipmentDeriveService
{
    public const SOURCE = 'derived';

    /**
     * Rebuild the derived per-shipment rows for one invoice. Idempotent.
     */
    public function deriveForInvoice(CarrierInvoice $invoice): int
    {
        $baseCategoryId = (int) (ChargeCategory::query()->where('name', 'Base Transportation')->value('id') ?? 0);

        DB::table('carrier_shipments')
            ->where('carrier_invoice_id', $invoice->id)
            ->where('source_type', self::SOURCE)
            ->delete();

        $now = now();
        $rows = DB::table('carrier_charges')
            ->where('carrier_invoice_id', $invoice->id)
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '<>', '')
            ->groupBy('tracking_number')
            ->selectRaw('tracking_number')
            ->selectRaw('MAX(service) as service')
            ->selectRaw('MAX(zone) as zone')
            ->selectRaw('MAX(weight) as billed_weight')
            ->selectRaw('MAX(ship_date) as ship_date')
            ->selectRaw('SUM(amount) as printed_total')
            // Heuristic: a tracking with no Base Transportation charge is third-party
            // (the carrier billed the transport elsewhere).
            ->selectRaw('SUM(CASE WHEN charge_category_id = ? THEN 1 ELSE 0 END) as base_count', [$baseCategoryId])
            ->get()
            ->map(fn ($r): array => [
                'carrier_invoice_id' => $invoice->id,
                'carrier_id' => $invoice->carrier_id,
                'tracking_number' => $r->tracking_number,
                'service' => $r->service,
                'zone' => $r->zone,
                'billed_weight' => $r->billed_weight,
                'ship_date' => $r->ship_date,
                'printed_total' => $r->printed_total,
                'is_third_party' => $r->base_count > 0 ? 0 : 1,
                'source_type' => self::SOURCE,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('carrier_shipments')->insert($chunk);
        }

        return count($rows);
    }
}
