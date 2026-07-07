<?php

use App\Enums\ChargeDriver;
use App\Models\Carrier;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'ups']);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-01-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function seedDriverCharge(int $invoiceId, int $carrierId, array $attrs): int
{
    return DB::table('carrier_charges')->insertGetId(array_merge([
        'carrier_invoice_id' => $invoiceId, 'carrier_id' => $carrierId, 'amount' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

test('backfill attributes driver by code, section, description, then default', function () {
    // code wins
    seedDriverCharge($this->invoiceId, $this->carrier->id, ['raw_charge_code' => 'ADC', 'raw_charge_description' => 'Ground']);
    // section (no code): make a shipment in the address_correction section
    $shipId = DB::table('carrier_shipments')->insertGetId([
        'carrier_invoice_id' => $this->invoiceId, 'carrier_id' => $this->carrier->id,
        'tracking_number' => '1ZSEC', 'section' => 'address_correction', 'source_type' => 'pdf',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    seedDriverCharge($this->invoiceId, $this->carrier->id, ['carrier_shipment_id' => $shipId, 'raw_charge_description' => '2nd Day Air']);
    // description only
    seedDriverCharge($this->invoiceId, $this->carrier->id, ['raw_charge_description' => 'Invalid Account Number Fee']);
    // default
    $normalId = seedDriverCharge($this->invoiceId, $this->carrier->id, ['raw_charge_description' => 'Fuel Surcharge']);

    $this->artisan('charges:backfill-drivers')->assertSuccessful();

    $rows = DB::table('carrier_charges')->pluck('driver', 'id');
    expect(DB::table('carrier_charges')->where('raw_charge_code', 'ADC')->value('driver'))->toBe(ChargeDriver::AddressCorrection->value);
    expect(DB::table('carrier_charges')->where('carrier_shipment_id', $shipId)->value('driver_source'))->toBe('pdf_section');
    expect(DB::table('carrier_charges')->where('raw_charge_description', 'Invalid Account Number Fee')->value('driver'))->toBe(ChargeDriver::ThirdPartyChargeback->value);
    expect($rows[$normalId])->toBe(ChargeDriver::Normal->value);
});

test('backfill is idempotent and does not overwrite an existing driver without --fresh', function () {
    seedDriverCharge($this->invoiceId, $this->carrier->id, ['raw_charge_code' => 'ADC', 'driver' => 'manual_override', 'driver_source' => 'manual']);

    $this->artisan('charges:backfill-drivers')->assertSuccessful();

    expect(DB::table('carrier_charges')->where('raw_charge_code', 'ADC')->value('driver'))->toBe('manual_override');

    $this->artisan('charges:backfill-drivers', ['--fresh' => true])->assertSuccessful();
    expect(DB::table('carrier_charges')->where('raw_charge_code', 'ADC')->value('driver'))->toBe(ChargeDriver::AddressCorrection->value);
});
