<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;

test('backfill derives categorized carrier_charges from invoice lines', function () {
    $ups = Carrier::factory()->create(['slug' => 'ups']);
    $category = ChargeCategory::create(['name' => 'Address Correction']);
    ChargeCodeMapping::create([
        'carrier_id' => $ups->id, 'match_type' => 'code', 'match_value' => 'ADC',
        'charge_category_id' => $category->id, 'priority' => 100,
    ]);

    $invoice = CarrierInvoice::create([
        'carrier_id' => $ups->id,
        'filename' => 'ups.csv',
        'file_hash' => hash('sha256', 'x'),
        'invoice_date' => '2026-05-16',
        'account_number' => '0E540W',
        'status' => 'completed',
    ]);
    $invoice->lines()->createMany([
        ['tracking_number' => '1Z1', 'charge_code' => 'ADC', 'charge_description' => 'Address Correction', 'charge_amount' => 18.40],
        ['tracking_number' => '1Z2', 'charge_code' => 'ADC', 'charge_description' => 'Address Correction', 'charge_amount' => 18.40],
    ]);

    $this->artisan('invoices:backfill-charges --fresh')->assertSuccessful();

    expect(CarrierCharge::count())->toBe(2);

    $charge = CarrierCharge::first();
    expect($charge->charge_category_id)->toBe($category->id);
    expect((float) $charge->amount)->toBe(18.40);
    expect($charge->invoice_date->toDateString())->toBe('2026-05-16');
    expect((float) CarrierCharge::sum('amount'))->toBe(36.80);
});
