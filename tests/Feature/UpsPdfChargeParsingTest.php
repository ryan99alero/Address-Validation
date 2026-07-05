<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Services\CarrierInvoiceParserService;
use App\Services\Invoices\UpsPdfChargeParser;

/**
 * A synthetic flattened UPS invoice covering the tricky shapes: an outbound shipment with
 * surcharges + customer dims, a $0 third-party block, an SCC block with a DIM audit (payable
 * on the 4th Adjustment column), and account-level Service Charges — plus the glossary.
 */
function syntheticUpsPdfText(): string
{
    return implode(' ', [
        'Invoice Date June 27, 2026 Invoice Number 0000691317266 Account Number 691317',
        'Summary of Charges Charges this period $ 32.84',
        'Outbound Shipping API Pickup Date Tracking Number Service ZIP Code Zone Weight Published Charge Incentive Credit Billed Charge',
        '06/15 1Z1111111111111111 Ground Commercial 88101 4 1 13.51 -5.19 8.32',
        'Delivery Area Surcharge 4.50 -1.80 2.70 Fuel Surcharge 4.77 -2.87 1.90',
        'Customer Entered Dimensions = 60 x 6 x 6 in Total 22.78 -9.86 12.92',
        '1st ref: 1 Sender : Shipping Rand Receiver: Store One Message Codes: ag',
        '1Z2222222222222222 Ground Commercial Third Party 46168 5 1 0.00 0.00',
        '1st ref: 2 Sender : Shipping Rand Receiver: Store Two Third Party: TP CO SPRINGFIELD MO 65802',
        'Shipping Charge Corrections Pickup Date Tracking Number Original Service/ Corrected Service ZIP Code Zone Weight Published Charge Incentive Credit Billed Charge Adjustment Amount',
        '06/16 1Z3333333333333333 Ground 28115 5 5 18.65 -9.81 8.84 Ground 28115 5 19.0 26.14 -14.27 11.87',
        'Audited Dimensions = 37 x 25 x 4 in Customer Entered Dimensions = 37 x 25 x 1 in',
        'Fuel Surcharge 1.98 -1.46 0.52 3.55 1st ref: 3 Sender : Shipping Rand Receiver: Store Three Message Codes : w',
        'Service Charges Date Explanation Published Charge Incentive Credit Billed Charge',
        '06/27 Weekly Service Charge 39.00 -25.00 14.00 Fuel Surcharge 10.14 -7.77 2.37 Total Service Charges 49.14 -32.77 16.37',
        'Invoice Messaging Code Message ag Minimum Rates Applied w Dimensional Weight adjustment based upon UPS audit',
    ]);
}

test('parser reconciles sections and captures shipment detail', function () {
    $r = (new UpsPdfChargeParser)->parse(syntheticUpsPdfText());

    expect($r['invoice_number'])->toBe('0000691317266');
    expect($r['invoice_date'])->toBe('2026-06-27');
    expect($r['account_number'])->toBe('691317');

    // 12.92 outbound + 3.55 SCC + 16.37 service = 32.84
    expect($r['reconciliation']['sections']['outbound'])->toBe(12.92);
    expect($r['reconciliation']['sections']['shipping_charge_correction'])->toBe(3.55);
    expect($r['reconciliation']['sections']['service'])->toBe(16.37);
    expect($r['reconciliation']['parsed_total'])->toBe(32.84);

    // Message-code glossary resolved from the codes shipments reference.
    expect($r['message_codes']['ag'])->toBe('Minimum Rates Applied');
    expect($r['message_codes']['w'])->toBe('Dimensional Weight adjustment based upon UPS audit');
});

test('parser captures dims, third-party zero-amount, and pickup date', function () {
    $shipments = collect((new UpsPdfChargeParser)->parse(syntheticUpsPdfText())['shipments']);

    $outbound = $shipments->firstWhere('tracking_number', '1Z1111111111111111');
    expect($outbound['ship_date'])->toBe('2026-06-15');
    expect($outbound['customer_dims'])->toBe('60 x 6 x 6');
    expect(array_sum(array_column($outbound['charges'], 'amount')))->toBe(12.92);

    $thirdParty = $shipments->firstWhere('tracking_number', '1Z2222222222222222');
    expect($thirdParty['third_party'])->toContain('TP CO');
    expect($thirdParty['charges'])->toBe([]); // $0 -> no charge rows
    expect($thirdParty['is_third_party'])->toBeTrue();
    expect($outbound['is_third_party'])->toBeFalse();

    $scc = $shipments->firstWhere('tracking_number', '1Z3333333333333333');
    expect($scc['customer_dims'])->toBe('37 x 25 x 1');
    expect($scc['audited_dims'])->toBe('37 x 25 x 4');
    expect($scc['billed_weight'])->toBe(19.0);
    expect($scc['message_codes'])->toBe(['w']);
});

test('importUpsPdf persists shipments, charges, and reconciliation flag', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    // Drive persistence through a temp PDF-less path by parsing synthetic text directly.
    $service = app(CarrierInvoiceParserService::class);
    $parsed = (new UpsPdfChargeParser)->parse(syntheticUpsPdfText());
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id,
        'invoice_number' => '691317266',
        'invoice_date' => '2026-06-27',
        'status' => 'pending',
    ]);

    $reflection = new ReflectionMethod($service, 'persistUpsPdf');
    $reflection->invoke($service, $invoice, $parsed, 32.84);

    $invoice->refresh();
    expect($invoice->charges_reconciled)->toBeTrue();
    expect((float) $invoice->charges_parsed_total)->toBe(32.84);

    // 3 shipments (incl. the $0 third-party); charges only for non-zero shipments + service.
    expect(CarrierShipment::where('carrier_invoice_id', $invoice->id)->count())->toBe(3);
    expect(round((float) CarrierCharge::where('carrier_invoice_id', $invoice->id)->sum('amount'), 2))->toBe(32.84);

    // The DIM-audited shipment is queryable.
    $audited = CarrierShipment::where('carrier_invoice_id', $invoice->id)->whereNotNull('audited_dims')->first();
    expect($audited->audited_dims)->toBe('37 x 25 x 4');
    expect(round((float) $audited->charges()->sum('amount'), 2))->toBe(3.55);
});

test('CSV charges evict PDF charges for the same invoice number, keeping shipment detail', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $service = app(CarrierInvoiceParserService::class);

    // PDF imports first.
    $parsed = (new UpsPdfChargeParser)->parse(syntheticUpsPdfText());
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id, 'invoice_number' => '691317266',
        'invoice_date' => '2026-06-27', 'status' => 'pending',
    ]);
    (new ReflectionMethod($service, 'persistUpsPdf'))->invoke($service, $invoice, $parsed, 32.84);
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'pdf')->count())->toBeGreaterThan(0);
    $shipmentCount = CarrierShipment::where('carrier_invoice_id', $invoice->id)->count();

    // A CSV for the SAME invoice number+date arrives (different filename is irrelevant).
    $csv = writeUpsCsv([upsRow('000000691317266', '2026-06-27', '1Z9999999999999999', '50.00', '2026-06-20')]);
    $service->importUpsCsv($carrier->id, $csv);
    @unlink($csv);

    // PDF charges gone, CSV charge owns it, shipment/audit rows retained.
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'pdf')->count())->toBe(0);
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'csv')->count())->toBe(1);
    expect(CarrierShipment::where('carrier_invoice_id', $invoice->id)->count())->toBe($shipmentCount);
});

test('PDF skips charges but keeps shipments when CSV already owns the invoice', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $service = app(CarrierInvoiceParserService::class);

    // CSV imports first.
    $csv = writeUpsCsv([upsRow('000000691317266', '2026-06-27', '1Z9999999999999999', '50.00', '2026-06-20')]);
    $service->importUpsCsv($carrier->id, $csv);
    @unlink($csv);
    $invoice = CarrierInvoice::where('invoice_number', '691317266')->sole();

    // Then the PDF for the same invoice.
    $parsed = (new UpsPdfChargeParser)->parse(syntheticUpsPdfText());
    (new ReflectionMethod($service, 'persistUpsPdf'))->invoke($service, $invoice, $parsed, 32.84);

    // No PDF charges added (CSV owns), but shipment/audit detail is captured.
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'pdf')->count())->toBe(0);
    expect(CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'csv')->count())->toBe(1);
    expect(CarrierShipment::where('carrier_invoice_id', $invoice->id)->whereNotNull('audited_dims')->count())->toBe(1);
});

test('unrecognized fee sub-sections are captured as labeled line items (Miscellaneous, Paper Commercial)', function () {
    // Real structures: an account-level Miscellaneous fee + a per-tracking Paper Commercial
    // surcharge — neither parsed structurally. Should come in as labeled charges that reconcile.
    $text = implode(' ', [
        'Invoice Date June 27, 2026 Invoice Number 0000000E540W266 Account Number 0E540W',
        'Summary of Charges Charges this period $ 59.99',
        'Adjustments & Other Charges Miscellaneous Explanation Published Charge Incentive Credit Billed Charge',
        'WEEKLY PRINTER SERVICE FEE For 1 PRINTERS AT $9.99 EACH FOR 26-JUN-2026 9.99 9.99 Total Miscellaneous 9.99 9.99',
        'Adjustments & Other Charges Paper Commercial Invoice Service Surcharge Export Date Tracking Number Description of Charges Published Charge Incentive Credit Billed Charge',
        '04/30 1Z6913170290730875 Paper Commercial Invoice Surcharge 25.00 25.00',
        '04/30 1Z6913170291481946 Paper Commercial Invoice Surcharge 25.00 25.00',
        'Total Paper Commercial Invoice Service Surcharge 50.00 50.00',
        'Total Adjustments & Other Charges 59.99',
    ]);

    $r = (new UpsPdfChargeParser)->parse($text);

    $other = collect($r['account_charges'])->where('section', 'other_fees');
    expect($other->firstWhere('amount', 9.99)['description'])->toContain('WEEKLY PRINTER SERVICE FEE');
    expect($other->firstWhere('amount', 50.0)['description'])->toContain('Paper Commercial Invoice Service Surcharge');
    // Both captured, nothing double-counted -> reconciles to the printed grand total.
    expect($r['reconciliation']['parsed_total'])->toBe(59.99);
});

test('a missed credit (parsed over the printed total) is captured as a negative residual line and reconciles', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $service = app(CarrierInvoiceParserService::class);
    $invoice = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'CR1', 'invoice_date' => '2026-06-27', 'status' => 'pending']);

    // Parser captured $105 of charges, but the printed grand total is $100 — i.e. a $5 credit
    // (e.g. a Residential/Commercial reclassification refund) we didn't recognize.
    $parsed = [
        'invoice_number' => 'CR1', 'account_number' => '1', 'invoice_date' => '2026-06-27',
        'message_codes' => [], 'shipments' => [],
        'account_charges' => [['section' => 'x', 'description' => 'Fee', 'amount' => 105.00]],
        'reconciliation' => ['parsed_total' => 105.00, 'sections' => []],
    ];
    (new ReflectionMethod($service, 'persistUpsPdf'))->invoke($service, $invoice, $parsed, 100.00);

    $invoice->refresh();
    expect($invoice->charges_reconciled)->toBeTrue();
    expect(round((float) $invoice->charges()->sum('amount'), 2))->toBe(100.0);
    expect($invoice->charges()->where('amount', -5.00)->where('raw_charge_description', 'like', '%credit%')->exists())->toBeTrue();
});

test('legacy-format UPS PDF (no charges-this-period summary) is skipped, not junk-imported', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    // A legacy Ricoh-layout blob: no "Charges this period", the parser grabs "PAYMENTS".
    $legacy = tempnam(sys_get_temp_dir(), 'legacy_').'.pdf';
    file_put_contents($legacy, "%PDF-1.3\nstream\nInvoice Number PAYMENTS Shipper Number 00000E540W\nendstream");

    $service = app(CarrierInvoiceParserService::class);
    $ids = $service->importFile($carrier->id, $legacy, 'A0000000E540W052-20120204.pdf');
    @unlink($legacy);

    expect($ids)->toBe([]);
    expect(CarrierInvoice::where('carrier_id', $carrier->id)->count())->toBe(0);
    // A no-invoice UPS PDF is recorded distinctly (skip reason) so it's findable/re-runnable.
    expect($service->lastSkipReason)->not->toBeNull();
});

test('importUpsPdf reconciles the real UPS invoice end to end', function () {
    $pdf = base_path('docs/UPS Invoice/Invoice_000000691317266_062726.PDF');
    if (! is_file($pdf)) {
        $this->markTestSkipped('Sample UPS PDF not present.');
    }

    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    // Exercise the real entry point (routes UPS+pdf → importUpsPdf, backfills filename).
    $ids = app(CarrierInvoiceParserService::class)->importFile($carrier->id, $pdf);

    expect($ids)->toHaveCount(1);
    $invoice = CarrierInvoice::find($ids[0]);

    expect($invoice->invoice_number)->toBe('691317266');
    expect($invoice->charges_reconciled)->toBeTrue();
    expect((float) $invoice->charges_parsed_total)->toBe(4411.77);
    expect((float) $invoice->charges_expected_total)->toBe(4411.77);

    // 6,934 shipments parsed; correction lines fed from the same PDF.
    expect(CarrierShipment::where('carrier_invoice_id', $invoice->id)->count())->toBe(6934);
    expect(CarrierShipment::where('carrier_invoice_id', $invoice->id)->whereNotNull('audited_dims')->count())->toBeGreaterThan(0);
    expect($invoice->correctionLines()->count())->toBe(8);

    // Summary counters shown on the invoice view are populated (not 0/empty).
    expect($invoice->total_records)->toBe(6934);          // "Shipments"
    expect($invoice->correction_records)->toBe(8);        // "Corrections"
    expect($invoice->new_corrections)->toBeGreaterThan(0); // "New Mappings"
    expect((float) $invoice->total_correction_charges)->toBe(4411.77); // "Total Charges"
    expect($invoice->filename)->not->toBeNull();
})->group('slow');
