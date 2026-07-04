<?php

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;

/**
 * Reconciliation = does our parsed charge sum match the grand total the carrier PRINTED on
 * the invoice? PDFs print that total (reconciled true/false); CSV has none (stays null).
 * The logic is shared across carriers via reconcileInvoice().
 */
function reconcile(CarrierInvoice $invoice, float $parsed, ?float $expected): void
{
    $m = new ReflectionMethod(CarrierInvoiceParserService::class, 'reconcileInvoice');
    $m->invoke(app(CarrierInvoiceParserService::class), $invoice, $parsed, $expected);
}

test('a matching parsed total reconciles', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'F1', 'invoice_date' => '2026-01-01', 'status' => 'completed']);

    reconcile($inv, 1234.56, 1234.56);

    $inv->refresh();
    expect($inv->charges_reconciled)->toBeTrue()
        ->and((float) $inv->charges_parsed_total)->toBe(1234.56)
        ->and((float) $inv->charges_expected_total)->toBe(1234.56);
});

test('a mismatched parsed total does not reconcile', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'F2', 'invoice_date' => '2026-01-01', 'status' => 'completed']);

    reconcile($inv, 1000.00, 1234.56);

    expect($inv->refresh()->charges_reconciled)->toBeFalse();
});

test('CSV (no printed total) leaves reconciled null — it is the source of truth', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'F3', 'invoice_date' => '2026-01-01', 'status' => 'completed']);

    reconcile($inv, 1234.56, null);

    $inv->refresh();
    expect($inv->charges_reconciled)->toBeNull()
        ->and((float) $inv->charges_parsed_total)->toBe(1234.56)
        ->and($inv->charges_expected_total)->toBeNull();
});
