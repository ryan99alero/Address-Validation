<?php

use App\Models\Carrier;
use App\Models\ChargebackPush;
use App\Services\Chargebacks\ChargebackEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    // Driver flagged to push, with a cost center; and one flagged but WITHOUT a cost center.
    DB::table('charge_drivers')->insert([
        ['key' => 'address_correction', 'label' => 'AC', 'disposition' => 'customer_chargebackable', 'push_to_pace' => true, 'pace_activity_code' => '72510', 'fuel_cost_center' => null, 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'audit_correction', 'label' => 'Audit', 'disposition' => 'customer_chargebackable', 'push_to_pace' => true, 'pace_activity_code' => '72530', 'fuel_cost_center' => '72550', 'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'residential_reclass', 'label' => 'Res', 'disposition' => 'customer_chargebackable', 'push_to_pace' => true, 'pace_activity_code' => '72540', 'fuel_cost_center' => null, 'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'late_fee', 'label' => 'Late', 'disposition' => 'informational', 'push_to_pace' => true, 'pace_activity_code' => null, 'fuel_cost_center' => null, 'sort_order' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'normal', 'label' => 'N', 'disposition' => 'informational', 'push_to_pace' => false, 'pace_activity_code' => null, 'fuel_cost_center' => null, 'sort_order' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->adc = DB::table('charge_categories')->insertGetId(['name' => 'Address Correction', 'abbreviation' => 'ADC', 'pace_cost_center' => '72510', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->fuel = DB::table('charge_categories')->insertGetId(['name' => 'Fuel Surcharge', 'abbreviation' => 'FUEL', 'pace_cost_center' => '72520', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->auditFee = DB::table('charge_categories')->insertGetId(['name' => 'Audit / Correction Fee', 'abbreviation' => 'AUD', 'pace_cost_center' => '72530', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(),
        'charges_reconciled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function ebCharge(array $attrs): int
{
    return DB::table('carrier_charges')->insertGetId(array_merge([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => '1Z1', 'amount' => 20.20, 'driver' => 'address_correction',
        'charge_category_id' => test()->adc, 'ship_date' => '2026-07-01', 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

test('eligible charge resolves the category cost center', function () {
    ebCharge([]); // address_correction + ADC
    ebCharge(['charge_category_id' => test()->fuel, 'amount' => 2.62]); // fuel

    $rows = app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]);

    expect($rows)->toHaveCount(2);
    expect($rows->firstWhere('charge_category_id', $this->adc)->activity_code)->toBe('72510');
    expect($rows->firstWhere('charge_category_id', $this->fuel)->activity_code)->toBe('72520');
});

test('fuel that rode in on an audit splits to 72550; address & residential fuel stay 72520', function () {
    ebCharge(['charge_category_id' => test()->fuel, 'driver' => 'audit_correction', 'amount' => 0.74, 'tracking_number' => '1ZAUD']);
    ebCharge(['charge_category_id' => test()->fuel, 'driver' => 'address_correction', 'amount' => 2.62, 'tracking_number' => '1ZADR']);
    ebCharge(['charge_category_id' => test()->fuel, 'driver' => 'residential_reclass', 'amount' => 0.11, 'tracking_number' => '1ZRES']);

    $rows = app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]);

    expect($rows->firstWhere('driver', 'audit_correction')->activity_code)->toBe('72550');
    expect($rows->firstWhere('driver', 'address_correction')->activity_code)->toBe('72520');
    expect($rows->firstWhere('driver', 'residential_reclass')->activity_code)->toBe('72520');
});

test('the audit FEE itself (Audit/Correction Fee category) still books to 72530, not 72550', function () {
    ebCharge(['charge_category_id' => test()->auditFee, 'driver' => 'audit_correction', 'amount' => 1.00, 'tracking_number' => '1ZFEE']);

    $rows = app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]);

    expect($rows->firstWhere('driver', 'audit_correction')->activity_code)->toBe('72530');
});

test('clearing a driver fuel_cost_center collapses its fuel back to the category default (all-in-one)', function () {
    DB::table('charge_drivers')->where('key', 'audit_correction')->update(['fuel_cost_center' => null]);
    ebCharge(['charge_category_id' => test()->fuel, 'driver' => 'audit_correction', 'amount' => 0.74, 'tracking_number' => '1ZAUD']);

    $rows = app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]);

    expect($rows->firstWhere('driver', 'audit_correction')->activity_code)->toBe('72520');
});

test('ineligible charges are excluded', function () {
    ebCharge(['driver' => 'normal']);                         // driver not push
    ebCharge(['driver' => 'late_fee']);                       // push but no cost center on driver
    ebCharge(['tracking_number' => null]);                    // no tracking
    ebCharge(['amount' => -5]);                               // credit
    ebCharge(['charge_category_id' => null]);                 // no category

    expect(app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]))->toBeEmpty();
});

test('an old invoice is excluded by the recent-window guard', function () {
    DB::table('carrier_invoices')->where('id', $this->invoiceId)->update(['invoice_date' => now()->subYear()->toDateString()]);
    ebCharge([]);

    expect(app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]))->toBeEmpty();
});

test('an unreconciled invoice is excluded', function () {
    DB::table('carrier_invoices')->where('id', $this->invoiceId)->update(['charges_reconciled' => false]);
    ebCharge([]);

    expect(app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]))->toBeEmpty();
});

test('a charge already in the ledger is not re-enqueued', function () {
    ebCharge([]);
    ChargebackPush::create([
        'dedupe_key' => ChargebackPush::dedupeKey($this->carrier->id, '1Z1', $this->adc, 20.20, '2026-07-01'),
        'carrier_id' => $this->carrier->id, 'tracking_number' => '1Z1', 'amount' => 20.20, 'status' => 'pushed',
    ]);

    expect(app(ChargebackEligibility::class)->forInvoices([$this->invoiceId]))->toBeEmpty();
});
