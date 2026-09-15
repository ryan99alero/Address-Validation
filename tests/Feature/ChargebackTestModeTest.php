<?php

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tmCharge(): array
{
    return [
        'carrier_charge_id' => null, 'carrier_id' => 1, 'carrier_invoice_id' => null,
        'tracking_number' => 'X1', 'charge_category_id' => 1, 'driver' => 'address_correction',
        'amount' => 20.20, 'ship_date' => null, 'activity_code' => '72510',
    ];
}

function tmRow(): ?ChargebackPush
{
    return ChargebackPush::where('dedupe_key', ChargebackPush::dedupeKey(1, 'X1', 1, 20.20, null))->first();
}

test('testModeHolds: off never holds; armed holds all but the allow-listed (case-insensitive)', function () {
    $pusher = new ChargebackPusher;

    $off = new IntegrationConnection(['chargeback_test_mode' => false]);
    expect($pusher->testModeHolds($off, '9999'))->toBeFalse();

    $armed = new IntegrationConnection([
        'chargeback_test_mode' => true, 'chargeback_test_customer_ids' => 'ID1, ID2, ID3',
    ]);
    expect($pusher->testModeHolds($armed, 'ID2'))->toBeFalse()   // listed → posts
        ->and($pusher->testModeHolds($armed, 'id2'))->toBeFalse() // case-insensitive
        ->and($pusher->testModeHolds($armed, 'ID9'))->toBeTrue()  // not listed → held
        ->and($pusher->testModeHolds($armed, null))->toBeTrue()   // unknown customer → held
        ->and($pusher->testModeHolds($armed, ''))->toBeTrue();

    // Armed but empty list → holds everything (nothing bills until a customer is added).
    $empty = new IntegrationConnection(['chargeback_test_mode' => true, 'chargeback_test_customer_ids' => null]);
    expect($pusher->testModeHolds($empty, 'ID1'))->toBeTrue();
});

test('testCustomerIds parses the CSV field — trimmed, lower-cased, de-duplicated', function () {
    $pusher = new ChargebackPusher;
    $conn = new IntegrationConnection(['chargeback_test_customer_ids' => 'ID1, ID2 , ID2, id3']);

    expect($pusher->testCustomerIds($conn))->toBe(['id1', 'id2', 'id3']);
});

test('test mode HOLDS a non-listed customer — recorded + reviewable, but no JobCost posted', function () {
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    $pusher->shouldReceive('recordOnly')->andReturnFalse();
    $pusher->shouldReceive('buildNotes')->andReturn('[CB:x] note');
    $pusher->shouldReceive('buildJobCostPayload')->andReturn(['job' => 'J9']);
    // A BILLABLE job whose customer is NOT on the allow-list → resolved + stamped, then held.
    $pusher->shouldReceive('lookupJobShipments')->andReturn([
        ['job' => 'J9', 'jobPart' => '01', 'jobChargesOK' => true, 'customer' => '9999', 'customerName' => 'ACME'],
    ]);
    $pusher->shouldReceive('testModeHolds')->andReturnTrue();

    (new PushChargeback(tmCharge()))->handle($pusher);

    expect(tmRow()->status)->toBe(ChargebackPush::STATUS_SKIPPED_TEST_MODE)
        ->and(tmRow()->pace_jobcost_id)->toBeNull()          // nothing posted to Pace
        ->and(tmRow()->pace_customer_id)->toBe('9999');      // customer stamped → row is reviewable / re-drivable
});

test('Audit-only wins absolutely: with record-only ON, test mode is never consulted and nothing posts', function () {
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('activeConnection')->andReturn(new IntegrationConnection(['driver' => 'pace']));
    $pusher->shouldReceive('pushEnabled')->andReturnTrue();
    $pusher->shouldReceive('recordOnly')->andReturnTrue();   // AUDIT-ONLY ON
    $pusher->shouldReceive('buildNotes')->andReturn('[CB:x] note');
    $pusher->shouldReceive('buildJobCostPayload')->andReturn(['job' => 'J9']);
    $pusher->shouldReceive('lookupJobShipments')->andReturn([
        ['job' => 'J9', 'jobPart' => '01', 'jobChargesOK' => true, 'customer' => '9999'],
    ]);
    $pusher->shouldNotReceive('testModeHolds'); // record-only returns first — test mode is moot

    (new PushChargeback(tmCharge()))->handle($pusher);

    expect(tmRow()->status)->toBe(ChargebackPush::STATUS_RECORDED)
        ->and(tmRow()->pace_jobcost_id)->toBeNull();          // no JobCost regardless of test mode
});
