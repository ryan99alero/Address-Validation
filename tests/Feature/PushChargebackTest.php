<?php

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pcCharge(): array
{
    return [
        'carrier_charge_id' => null, 'carrier_id' => 1, 'carrier_invoice_id' => null,
        'tracking_number' => 'X1', 'charge_category_id' => 1, 'driver' => 'address_correction',
        'amount' => 20.20, 'ship_date' => null, 'activity_code' => '72510',
    ];
}

test('master toggle OFF ignores the charge — no ledger row, no Pace call', function () {
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturnNull();
    $pusher->shouldReceive('pushEnabled')->andReturnFalse();
    $pusher->shouldNotReceive('lookupJobShipments');

    (new PushChargeback(pcCharge()))->handle($pusher);

    expect(ChargebackPush::count())->toBe(0);
});

test('an already-pushed charge is never pushed again (returns before touching Pace)', function () {
    $key = ChargebackPush::dedupeKey(1, 'X1', 1, 20.20, null);
    ChargebackPush::create(['dedupe_key' => $key, 'carrier_id' => 1, 'tracking_number' => 'X1', 'amount' => 20.20, 'status' => 'pushed', 'pace_jobcost_id' => '999']);

    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    $pusher->shouldNotReceive('lookupJobShipments'); // terminal state → never reaches Pace

    (new PushChargeback(pcCharge()))->handle($pusher);

    expect(ChargebackPush::where('dedupe_key', $key)->count())->toBe(1)
        ->and(ChargebackPush::where('dedupe_key', $key)->first()->pace_jobcost_id)->toBe('999');
});

test('an OPEN job with jobChargesOK=false is skipped, not billed (gate is jobChargesOK, not openJob)', function () {
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    // The job is OPEN but Pace says charges are NOT ok — must NOT be billed.
    $pusher->shouldReceive('lookupJobShipments')->andReturn([
        ['job' => 'J1', 'jobPart' => '01', 'openJob' => true, 'jobChargesOK' => false],
    ]);

    (new PushChargeback(pcCharge()))->handle($pusher);

    $row = ChargebackPush::where('dedupe_key', ChargebackPush::dedupeKey(1, 'X1', 1, 20.20, null))->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(ChargebackPush::STATUS_SKIPPED_JOB_CLOSED)
        ->and($row->pace_jobcost_id)->toBeNull();
});
