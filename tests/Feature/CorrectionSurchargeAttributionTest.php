<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\ChargeCategory;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attrCharge(int $invId, int $carrierId, string $trk, string $driver, int $catId, float $amt): void
{
    CarrierCharge::create([
        'carrier_invoice_id' => $invId, 'carrier_id' => $carrierId, 'tracking_number' => $trk,
        'driver' => $driver, 'driver_source' => 'test', 'charge_category_id' => $catId,
        'amount' => $amt, 'source_type' => 'csv',
    ]);
}

test('correction-only tracking surcharges inherit the correction driver; real-shipment surcharges do not', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $base = ChargeCategory::create(['name' => 'Base Transportation']);
    $fuel = ChargeCategory::create(['name' => 'Fuel Surcharge']);
    $adc = ChargeCategory::create(['name' => 'Address Correction']);
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'T1', 'status' => 'pending']);

    // Tracking AAA: correction-only adjustment line (fee + its fuel, NO base transport) — like FedEx 872479799239.
    attrCharge($inv->id, $carrier->id, 'AAA', 'address_correction', $adc->id, 25.50);
    attrCharge($inv->id, $carrier->id, 'AAA', 'normal', $fuel->id, 6.82);
    // Tracking BBB: a real shipment (base + fuel) that also got a correction — fuel stays with the shipment.
    attrCharge($inv->id, $carrier->id, 'BBB', 'normal', $base->id, 10.00);
    attrCharge($inv->id, $carrier->id, 'BBB', 'normal', $fuel->id, 1.75);
    attrCharge($inv->id, $carrier->id, 'BBB', 'address_correction', $adc->id, 25.50);

    (fn () => $this->attributeCorrectionSurcharges($inv))->call(app(CarrierInvoiceParserService::class));

    // AAA's fuel now carries the correction driver → chargeback-eligible (books to 72520 via its Fuel category).
    expect(CarrierCharge::where('tracking_number', 'AAA')->where('charge_category_id', $fuel->id)->first()->driver)->toBe('address_correction')
        // BBB has base transport, so its fuel belongs to the shipment — left as normal.
        ->and(CarrierCharge::where('tracking_number', 'BBB')->where('charge_category_id', $fuel->id)->first()->driver)->toBe('normal')
        ->and(CarrierCharge::where('tracking_number', 'BBB')->where('charge_category_id', $base->id)->first()->driver)->toBe('normal');
});
