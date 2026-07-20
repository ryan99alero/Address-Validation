<?php

use App\Jobs\PushChargeback;
use App\Models\Carrier;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackEligibility;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    DB::table('charge_drivers')->insert([
        ['key' => 'address_correction', 'label' => 'AC', 'disposition' => 'customer_chargebackable', 'push_to_pace' => true, 'pace_activity_code' => '72510', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->adc = DB::table('charge_categories')->insertGetId(['name' => 'Address Correction', 'abbreviation' => 'ADC', 'pace_cost_center' => '72510', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(),
        'charges_reconciled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function rdCharge(array $attrs = []): int
{
    return DB::table('carrier_charges')->insertGetId(array_merge([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => '1Z1', 'amount' => 20.20, 'driver' => 'address_correction',
        'charge_category_id' => test()->adc, 'ship_date' => '2026-07-01', 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function rdSkip(int $chargeId): ChargebackPush
{
    return ChargebackPush::create([
        'dedupe_key' => ChargebackPush::dedupeKey(test()->carrier->id, '1Z1', test()->adc, 20.20, '2026-07-01'),
        'carrier_charge_id' => $chargeId, 'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => '1Z1', 'charge_category_id' => test()->adc, 'driver' => 'address_correction',
        'amount' => 20.20, 'ship_date' => '2026-07-01', 'activity_code' => '72510', 'attempts' => 1,
        'status' => ChargebackPush::STATUS_SKIPPED_NO_JOBSHIPMENT,
    ]);
}

function fakePusher(bool $enabled): void
{
    test()->mock(ChargebackPusher::class, function ($m) use ($enabled) {
        $m->shouldReceive('activeConnection')->andReturn($enabled ? new IntegrationConnection(['driver' => 'pace']) : null);
        $m->shouldReceive('pushEnabled')->andReturn($enabled);
    });
}

test('forChargeIds returns an already-ledgered charge that forInvoices would reject', function () {
    $chargeId = rdCharge();
    rdSkip($chargeId); // ledger row exists → forInvoices rejects it

    expect(app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]))->toBeEmpty()
        ->and(app(ChargebackEligibility::class)->forChargeIds([$chargeId]))->toHaveCount(1)
        ->and(app(ChargebackEligibility::class)->forChargeIds([$chargeId])->first()->activity_code)->toBe('72510');
});

test('re-drive resets a skipped_no_jobshipment row to pending and re-dispatches it', function () {
    Bus::fake();
    fakePusher(enabled: true);
    $row = rdSkip(rdCharge());

    $this->artisan('chargebacks:redrive')->assertSuccessful();

    expect($row->refresh()->status)->toBe(ChargebackPush::STATUS_PENDING)
        ->and($row->attempts)->toBe(0);
    Bus::assertDispatched(PushChargeback::class, fn (PushChargeback $j): bool => $j->charge['tracking_number'] === '1Z1');
});

test('dry-run changes nothing and dispatches nothing', function () {
    Bus::fake();
    fakePusher(enabled: true);
    $row = rdSkip(rdCharge());

    $this->artisan('chargebacks:redrive', ['--dry-run' => true])->assertSuccessful();

    expect($row->refresh()->status)->toBe(ChargebackPush::STATUS_SKIPPED_NO_JOBSHIPMENT);
    Bus::assertNotDispatched(PushChargeback::class);
});

test('re-drive aborts when the master push toggle is OFF', function () {
    Bus::fake();
    fakePusher(enabled: false);
    $row = rdSkip(rdCharge());

    $this->artisan('chargebacks:redrive')->assertFailed();

    expect($row->refresh()->status)->toBe(ChargebackPush::STATUS_SKIPPED_NO_JOBSHIPMENT);
    Bus::assertNotDispatched(PushChargeback::class);
});

test('a row whose charge is no longer eligible is left in place', function () {
    Bus::fake();
    fakePusher(enabled: true);
    $chargeId = rdCharge();
    $row = rdSkip($chargeId);
    // Charge becomes ineligible (invoice unreconciled) → forChargeIds omits it.
    DB::table('carrier_invoices')->where('id', $this->invoiceId)->update(['charges_reconciled' => false]);

    $this->artisan('chargebacks:redrive')->assertSuccessful();

    expect($row->refresh()->status)->toBe(ChargebackPush::STATUS_SKIPPED_NO_JOBSHIPMENT);
    Bus::assertNotDispatched(PushChargeback::class);
});
