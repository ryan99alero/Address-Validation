<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CarrierCharge::scopeBillingType() correlates a charge to its shipment's authoritative billing_type
 * on tracking + carrier — the query behind the expanded All Charges "Billing" filter.
 */
test('scopeBillingType filters charges by the shipment billing_type via tracking', function () {
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $fedex->id, 'invoice_number' => 'F1', 'invoice_date' => '2026-01-03',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $shipment = function (string $tracking, string $billingType) use ($fedex, $invoiceId): void {
        DB::table('carrier_shipments')->insert([
            'carrier_id' => $fedex->id, 'carrier_invoice_id' => $invoiceId, 'source_type' => 'csv',
            'tracking_number' => $tracking, 'billing_type' => $billingType, 'is_third_party' => $billingType === 'third_party',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $charge = function (string $tracking) use ($fedex, $invoiceId): void {
        DB::table('carrier_charges')->insert([
            'carrier_id' => $fedex->id, 'carrier_invoice_id' => $invoiceId, 'source_type' => 'csv',
            'tracking_number' => $tracking, 'amount' => 5.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $shipment('T-PPD', 'prepaid');
    $shipment('T-COL', 'collect');
    $shipment('T-3P', 'third_party');
    $charge('T-PPD');
    $charge('T-COL');
    $charge('T-3P');
    $charge('T-NONE'); // no matching shipment → belongs to no bucket

    expect(CarrierCharge::query()->billingType('collect')->pluck('tracking_number')->all())->toBe(['T-COL'])
        ->and(CarrierCharge::query()->billingType('third_party')->pluck('tracking_number')->all())->toBe(['T-3P'])
        ->and(CarrierCharge::query()->billingType('prepaid')->pluck('tracking_number')->all())->toBe(['T-PPD']);
});
