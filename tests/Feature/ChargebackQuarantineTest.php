<?php

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function qCharge(array $over = []): array
{
    return array_merge([
        'carrier_id' => 1, 'carrier_invoice_id' => 10, 'invoice_number' => 'INV1', 'invoice_date' => '2026-07-11',
        'tracking_number' => '1ZQ', 'charge_category_id' => 1, 'driver' => 'address_correction',
        'amount' => 20.20, 'activity_code' => '72510',
    ], $over);
}

function qPusher(bool $expectLookup): ChargebackPusher
{
    $p = Mockery::mock(ChargebackPusher::class);
    $p->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $p->shouldReceive('pushEnabled')->andReturnTrue();
    // Quarantined charges never reach Pace; non-quarantined ones resolve (return no shipment → clean skip).
    if ($expectLookup) {
        $p->shouldReceive('lookupJobShipments')->andReturn([]);
    } else {
        $p->shouldNotReceive('lookupJobShipments');
    }

    return $p;
}

function qPosted(array $over): ChargebackPush
{
    return ChargebackPush::create(array_merge([
        'txn_id' => 'CB1-posted-'.uniqid(), 'dedupe_key' => 'd'.uniqid(), 'carrier_id' => 1,
        'carrier_invoice_id' => 10, 'tracking_number' => '1ZQ', 'status' => 'pushed', 'pace_jobcost_id' => '900',
    ], $over));
}

test('a re-imported charge with a CORRECTED AMOUNT (same category) is quarantined, not posted', function () {
    $posted = qPosted(['activity_code' => '72510', 'amount' => 18.40]);

    (new PushChargeback(qCharge(['amount' => 20.20])))->handle(qPusher(expectLookup: false));

    $row = ChargebackPush::where('txn_id', ChargebackPush::identity(qCharge(['amount' => 20.20])))->first();
    expect($row->status)->toBe(ChargebackPush::STATUS_QUARANTINED)
        ->and($row->conflict_with_id)->toBe($posted->id)
        ->and($row->conflict_reason)->toBe(ChargebackPush::CONFLICT_AMOUNT);
});

test('a RECATEGORIZED charge (same amount, different category) is quarantined', function () {
    $posted = qPosted(['activity_code' => '72500', 'amount' => 20.20]);

    (new PushChargeback(qCharge(['activity_code' => '72510', 'amount' => 20.20])))->handle(qPusher(expectLookup: false));

    $row = ChargebackPush::where('txn_id', ChargebackPush::identity(qCharge()))->first();
    expect($row->status)->toBe(ChargebackPush::STATUS_QUARANTINED)
        ->and($row->conflict_with_id)->toBe($posted->id)
        ->and($row->conflict_reason)->toBe(ChargebackPush::CONFLICT_CATEGORY);
});

test('a genuinely different charge on the same shipment (both amount AND category differ) is NOT quarantined', function () {
    qPosted(['activity_code' => '72520', 'amount' => 3.28]); // a legit fuel charge on the same shipment

    (new PushChargeback(qCharge()))->handle(qPusher(expectLookup: true));

    $row = ChargebackPush::where('txn_id', ChargebackPush::identity(qCharge()))->first();
    expect($row->status)->not->toBe(ChargebackPush::STATUS_QUARANTINED);
});

test('force bypasses the quarantine guard (reviewer Push-anyway)', function () {
    qPosted(['activity_code' => '72510', 'amount' => 18.40]);

    (new PushChargeback(qCharge(['amount' => 20.20]), force: true))->handle(qPusher(expectLookup: true));

    $row = ChargebackPush::where('txn_id', ChargebackPush::identity(qCharge(['amount' => 20.20])))->first();
    expect($row->status)->not->toBe(ChargebackPush::STATUS_QUARANTINED);
});

test('a quarantined row is terminal — a re-dispatch does not re-process it', function () {
    $posted = qPosted(['activity_code' => '72510', 'amount' => 18.40]);
    (new PushChargeback(qCharge(['amount' => 20.20])))->handle(qPusher(expectLookup: false));
    // Second dispatch of the same charge: firstOrCreate finds the quarantined row, terminal → no-op.
    (new PushChargeback(qCharge(['amount' => 20.20])))->handle(qPusher(expectLookup: false));

    expect(ChargebackPush::where('status', ChargebackPush::STATUS_QUARANTINED)->count())->toBe(1);
});
