<?php

use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use App\Models\CompanySetting;

/**
 * Carriers sometimes encode the shipper (RAND) as the "original recipient" on
 * returns/undeliverables. The invoice line must keep that factual data, but the
 * validation cache must NOT learn to "correct" our own address to a customer's.
 */
beforeEach(function () {
    // Mirror prod exactly: origin stored as "2820 South Hoover" (spelled-out directional,
    // no suffix) while carrier lines say "2820 S. Hoover Rd" — must still match.
    CompanySetting::create([
        'company_name' => 'RAND',
        'address_line_1' => '2820 South Hoover',
        'city' => 'Wichita',
        'state' => 'KS',
        'postal_code' => '67215',
        'country_code' => 'US',
    ]);
});

test('a correction whose original is our own address does not create a cache variant', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $invoice = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'F1', 'invoice_date' => '2026-01-01', 'status' => 'completed']);

    // FedEx recorded our own address as the "original recipient" (a return artifact).
    $line = CarrierInvoiceLine::create([
        'carrier_invoice_id' => $invoice->id,
        'tracking_number' => '380584955676',
        'original_address_1' => '2820 S. Hoover Rd', 'original_city' => 'WICHITA', 'original_state' => 'KS', 'original_postal' => '67215',
        'corrected_address_1' => '2439 W 10TH ST', 'corrected_city' => 'GREELEY', 'corrected_state' => 'CO', 'corrected_postal' => '80634',
        'charge_code' => 'ADC', 'charge_description' => 'Address Correction', 'charge_amount' => 0.0,
    ]);

    $line->linkToCorrectionCache();

    // Factual invoice data is preserved: the line still links to the corrected address.
    expect($line->fresh()->corrected_address_id)->not->toBeNull();
    // But NO variant was created from our own address.
    expect(AddressVariant::where('input_address_1', 'like', '%HOOVER%')->count())->toBe(0);
});

test('a normal customer correction still creates a cache variant', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $invoice = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'U1', 'invoice_date' => '2026-01-01', 'status' => 'completed']);

    $line = CarrierInvoiceLine::create([
        'carrier_invoice_id' => $invoice->id,
        'tracking_number' => '1Z9999999999999999',
        'original_address_1' => '2439 10TH ST', 'original_city' => 'GREELEY', 'original_state' => 'CO', 'original_postal' => '80631',
        'corrected_address_1' => '2439 W 10TH ST', 'corrected_city' => 'GREELEY', 'corrected_state' => 'CO', 'corrected_postal' => '80634',
        'charge_code' => 'ADC', 'charge_description' => 'Address Correction', 'charge_amount' => 0.0,
    ]);

    $line->linkToCorrectionCache();

    expect(AddressVariant::where('input_address_1', 'like', '%10TH ST%')->count())->toBe(1);
});
