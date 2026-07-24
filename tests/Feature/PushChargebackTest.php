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
    $txn = ChargebackPush::identity(pcCharge());
    ChargebackPush::create(['txn_id' => $txn, 'dedupe_key' => 'legacy-1', 'carrier_id' => 1, 'tracking_number' => 'X1', 'charge_category_id' => 1, 'amount' => 20.20, 'status' => 'pushed', 'pace_jobcost_id' => '999']);

    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    $pusher->shouldNotReceive('lookupJobShipments'); // terminal state → never reaches Pace

    (new PushChargeback(pcCharge()))->handle($pusher);

    expect(ChargebackPush::where('txn_id', $txn)->count())->toBe(1)
        ->and(ChargebackPush::where('txn_id', $txn)->first()->pace_jobcost_id)->toBe('999');
});

test('the same charge re-imported with a different ship_date is claimed only once (the dup bug)', function () {
    // Gen 1: ship_date null. Gen 2: same charge, ship_date populated by a re-import. Old dedupe_key
    // forked these into two JobCosts; the txn_id identity (ship_date-independent) must not.
    $gen1 = pcCharge() + ['invoice_number' => 'INV9', 'invoice_date' => '2026-07-11', 'ship_date' => null];
    $gen2 = pcCharge() + ['invoice_number' => 'INV9', 'invoice_date' => '2026-07-11', 'ship_date' => '2026-07-07'];

    expect(ChargebackPush::identity($gen1))->toBe(ChargebackPush::identity($gen2));

    ChargebackPush::create(['txn_id' => ChargebackPush::identity($gen1), 'dedupe_key' => 'legacy-g1', 'carrier_id' => 1, 'tracking_number' => 'X1', 'amount' => 20.20, 'status' => 'pushed', 'pace_jobcost_id' => '999']);

    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    $pusher->shouldNotReceive('lookupJobShipments');

    (new PushChargeback($gen2))->handle($pusher); // the re-import re-push

    expect(ChargebackPush::count())->toBe(1); // NOT two
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
        ->and($row->pace_jobcost_id)->toBeNull()
        // The job number is stamped on the skip so it's diagnosable without a Pace lookup.
        ->and($row->pace_job)->toBe('J1');
});

test('record-only mode writes the full record but never posts a JobCost to Pace', function () {
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    $pusher->shouldReceive('recordOnly')->andReturnTrue();   // record-only ON
    $pusher->shouldReceive('buildNotes')->andReturn('[CB:x] note');
    $pusher->shouldReceive('buildJobCostPayload')->andReturn(['job' => 'J9']);
    // A BILLABLE job (jobChargesOK=true) that would normally push — record-only must resolve + record it
    // WITHOUT any Pace write. enrichmentFrom/repShipment are static, so they run for real.
    $pusher->shouldReceive('lookupJobShipments')->andReturn([
        ['job' => 'J9', 'jobPart' => '01', 'jobChargesOK' => true, 'customer' => '3035',
            'customerName' => 'KUBOTA', 'csrName' => 'HEATHER', 'salespersonName' => 'RANDALL V'],
    ]);

    (new PushChargeback(pcCharge()))->handle($pusher);

    $row = ChargebackPush::where('dedupe_key', ChargebackPush::dedupeKey(1, 'X1', 1, 20.20, null))->first();
    expect($row->status)->toBe(ChargebackPush::STATUS_RECORDED)
        ->and($row->pace_jobcost_id)->toBeNull()          // nothing posted to Pace
        ->and($row->pace_job)->toBe('J9')
        ->and($row->pace_customer_name)->toBe('KUBOTA')
        ->and($row->pace_csr_name)->toBe('HEATHER')
        ->and($row->pace_salesperson_name)->toBe('RANDALL V');
});

test('a closed-job skip stamps customer/CSR/salesperson so it can be sent to the reps', function () {
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    // Closed job (not billable), but Pace returned the responsible customer/CSR/salesperson in the
    // same Carton lookup — these must land on the ledger for the "couldn't bill" download.
    $pusher->shouldReceive('lookupJobShipments')->andReturn([
        ['job' => 'J1', 'jobPart' => '01', 'jobChargesOK' => false, 'customer' => '3035',
            'customerName' => 'KUBOTA - SOURCING GROUP', 'csrName' => 'HEATHER', 'salespersonName' => 'RANDALL V'],
    ]);

    (new PushChargeback(pcCharge()))->handle($pusher);

    $row = ChargebackPush::where('dedupe_key', ChargebackPush::dedupeKey(1, 'X1', 1, 20.20, null))->first();
    expect($row->status)->toBe(ChargebackPush::STATUS_SKIPPED_JOB_CLOSED)
        ->and($row->pace_customer_id)->toBe('3035')
        ->and($row->pace_customer_name)->toBe('KUBOTA - SOURCING GROUP')
        ->and($row->pace_csr_name)->toBe('HEATHER')
        ->and($row->pace_salesperson_name)->toBe('RANDALL V');
});
