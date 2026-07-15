<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * UPS charges come from the CSV (authoritative), which has no per-shipment structure, while
 * shipment rows come from the PDF. On import we link CSV charges to a shipment row by tracking
 * so the Per-Shipment Costs view reconciles — attributing a multi-section tracking's whole
 * cost to its primary (outbound) row without double-counting the sibling rows.
 */
it('links CSV charges to PDF shipment rows so Per-Shipment reconciles', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id, 'invoice_number' => '691317999',
        'invoice_date' => '2026-06-27', 'status' => 'pending',
    ]);

    // PDF-provided shipment rows: TRKA (single) and TRKB (outbound + a correction section).
    $trkA = CarrierShipment::create([
        'carrier_invoice_id' => $invoice->id, 'carrier_id' => $carrier->id,
        'tracking_number' => 'TRKA', 'section' => 'outbound', 'source_type' => 'pdf', 'is_third_party' => false,
    ]);
    $trkBout = CarrierShipment::create([
        'carrier_invoice_id' => $invoice->id, 'carrier_id' => $carrier->id,
        'tracking_number' => 'TRKB', 'section' => 'outbound', 'source_type' => 'pdf', 'is_third_party' => false,
        'printed_total' => 99, // stale PDF value — must not double-count after linking
    ]);
    $trkBcorr = CarrierShipment::create([
        'carrier_invoice_id' => $invoice->id, 'carrier_id' => $carrier->id,
        'tracking_number' => 'TRKB', 'section' => 'shipping_charge_correction', 'source_type' => 'pdf', 'is_third_party' => false,
        'printed_total' => 99,
    ]);

    // CSV charges for the same invoice number/date: TRKA $10; TRKB $20 + $30.
    $csv = writeUpsCsv([
        upsRow('000000691317999', '2026-06-27', 'TRKA', '10.00', '2026-06-20'),
        upsRow('000000691317999', '2026-06-27', 'TRKB', '20.00', '2026-06-20'),
        upsRow('000000691317999', '2026-06-27', 'TRKB', '30.00', '2026-06-20'),
    ]);
    app(CarrierInvoiceParserService::class)->importUpsCsv($carrier->id, $csv);
    @unlink($csv);

    // Every CSV charge is now attached to a shipment row.
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->whereNull('carrier_shipment_id')->count())->toBe(0);

    // TRKB's whole cost lands on the outbound (primary) row; the correction row stays $0.
    expect((float) $trkBout->refresh()->printed_total)->toBe(50.0)
        ->and((float) $trkBcorr->refresh()->printed_total)->toBe(0.0)
        ->and((float) $trkA->refresh()->printed_total)->toBe(10.0);

    // Per-Shipment total reconciles to the invoice's charges (10 + 20 + 30), no double-count.
    expect((float) $invoice->shipments()->sum('printed_total'))->toBe(60.0)
        ->and((float) $invoice->charges()->sum('amount'))->toBe(60.0);
});

it('is a no-op when the PDF shipments have not arrived yet', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    // CSV imports before any PDF: charges exist, no shipments to link to yet.
    $csv = writeUpsCsv([upsRow('000000691317888', '2026-06-27', 'TRKZ', '15.00', '2026-06-20')]);
    app(CarrierInvoiceParserService::class)->importUpsCsv($carrier->id, $csv);
    @unlink($csv);

    $invoice = CarrierInvoice::where('invoice_number', '691317888')->sole();
    // Charge stored but unlinked (no shipments) — importUpsPdf will link it once it runs.
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->count())->toBe(1)
        ->and(CarrierCharge::where('carrier_invoice_id', $invoice->id)->whereNull('carrier_shipment_id')->count())->toBe(1);
});
