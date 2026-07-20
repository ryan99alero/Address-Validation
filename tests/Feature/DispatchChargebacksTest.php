<?php

use App\Jobs\PushInvoiceChargebacks;
use App\Models\Carrier;
use App\Models\IntegrationConnection;
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

function dcCharge(array $attrs = []): int
{
    return DB::table('carrier_charges')->insertGetId(array_merge([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => '1Z9', 'amount' => 20.20, 'driver' => 'address_correction',
        'charge_category_id' => test()->adc, 'ship_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function dcPusher(bool $enabled): void
{
    test()->mock(ChargebackPusher::class, function ($m) use ($enabled) {
        $m->shouldReceive('activeConnection')->andReturn($enabled ? new IntegrationConnection(['driver' => 'pace']) : null);
        $m->shouldReceive('pushEnabled')->andReturn($enabled);
    });
}

test('dispatch enqueues PushInvoiceChargebacks for recent reconciled invoices with a backlog', function () {
    Bus::fake();
    dcPusher(enabled: true);
    dcCharge(); // eligible, not in ledger

    $this->artisan('chargebacks:dispatch')->assertSuccessful();

    Bus::assertDispatched(PushInvoiceChargebacks::class, fn (PushInvoiceChargebacks $j): bool => in_array($this->invoiceId, $j->invoiceIds, true));
});

test('dispatch aborts when the push toggle is OFF', function () {
    Bus::fake();
    dcPusher(enabled: false);
    dcCharge();

    $this->artisan('chargebacks:dispatch')->assertFailed();

    Bus::assertNotDispatched(PushInvoiceChargebacks::class);
});

test('dry-run reports but dispatches nothing', function () {
    Bus::fake();
    dcPusher(enabled: true);
    dcCharge();

    $this->artisan('chargebacks:dispatch', ['--dry-run' => true])->assertSuccessful();

    Bus::assertNotDispatched(PushInvoiceChargebacks::class);
});

test('an old invoice past the 6-month window is not dispatched', function () {
    Bus::fake();
    dcPusher(enabled: true);
    DB::table('carrier_invoices')->where('id', $this->invoiceId)->update(['invoice_date' => now()->subYear()->toDateString()]);
    dcCharge();

    $this->artisan('chargebacks:dispatch')->assertSuccessful();

    Bus::assertNotDispatched(PushInvoiceChargebacks::class);
});
