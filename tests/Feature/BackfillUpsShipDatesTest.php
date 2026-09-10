<?php

use App\Console\Commands\BackfillUpsShipDates;
use App\Models\Carrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-09-05',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function upsShipment(string $tracking, ?string $shipDate): void
{
    DB::table('carrier_shipments')->insert([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => $tracking, 'source_type' => 'pdf', 'ship_date' => $shipDate,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function upsCharge(string $tracking, ?string $shipDate): void
{
    DB::table('carrier_charges')->insert([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => $tracking, 'source_type' => 'pdf', 'amount' => 10.00, 'ship_date' => $shipDate,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('fills null ship_date on UPS pdf shipments + charges, leaves dated rows untouched', function () {
    upsShipment('1ZAAA', null);
    upsCharge('1ZAAA', null);
    upsShipment('1ZBBB', '2026-01-01'); // already dated — must not change

    $result = app(BackfillUpsShipDates::class)->applyShipDates($this->carrier->id, [
        '1ZAAA' => '2026-08-21', '1ZBBB' => '2026-09-09',
    ], false);

    expect($result['shipments'])->toBe(1)
        ->and($result['charges'])->toBe(1)
        ->and(DB::table('carrier_shipments')->where('tracking_number', '1ZAAA')->value('ship_date'))->toBe('2026-08-21')
        ->and(DB::table('carrier_charges')->where('tracking_number', '1ZAAA')->value('ship_date'))->toBe('2026-08-21')
        ->and(DB::table('carrier_shipments')->where('tracking_number', '1ZBBB')->value('ship_date'))->toBe('2026-01-01');
});

test('dry run reports counts but writes nothing', function () {
    upsShipment('1ZAAA', null);

    $result = app(BackfillUpsShipDates::class)->applyShipDates($this->carrier->id, ['1ZAAA' => '2026-08-21'], true);

    expect($result['shipments'])->toBe(1)
        ->and(DB::table('carrier_shipments')->where('tracking_number', '1ZAAA')->value('ship_date'))->toBeNull();
});
