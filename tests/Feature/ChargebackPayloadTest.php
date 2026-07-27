<?php

use App\Models\ChargebackPush;
use App\Services\Chargebacks\ChargebackPusher;

test('the JobCost payload writes the txn_id into ioID', function () {
    $payload = (new ChargebackPusher)->buildJobCostPayload([
        'job' => 'M1', 'jobPart' => '01', 'activityCode' => '72510',
        'amount' => 20.20, 'tracking' => '1Z9', 'notes' => 'n', 'txnId' => 'CB1-abc123',
    ]);

    expect($payload['ioID'])->toBe('CB1-abc123')
        ->and($payload['sourceID'])->toBe('1Z9')      // tracking still in sourceID
        ->and($payload['cost'])->toBe('20.20');
});

test('the Pace ioID is truncated to 50 chars (Pace 500s on longer)', function () {
    $txnId = ChargebackPush::identity([
        'carrier_id' => 1, 'tracking_number' => '1Z6913170395546500', 'activity_code' => '72530',
        'amount' => 0.12, 'invoice_number' => '0000691317015', 'invoice_date' => '2015-01-03',
    ]);
    expect(strlen($txnId))->toBe(52); // 'CB1-' + 48 hex — longer than Pace allows

    $payload = (new ChargebackPusher)->buildJobCostPayload([
        'job' => 'M1', 'jobPart' => '01', 'activityCode' => '72530',
        'amount' => 0.12, 'tracking' => '1Z9', 'notes' => 'n', 'txnId' => $txnId,
    ]);

    expect(strlen($payload['ioID']))->toBeLessThanOrEqual(50)
        ->and($payload['ioID'])->toBe(substr($txnId, 0, 50));
});

test('the human notes token is the txn_id, not a DB row id', function () {
    $notes = (new ChargebackPusher)->buildNotes('CB1-abc123', ['carrier' => 'UPS', 'label' => 'Address Correction']);

    expect($notes)->toStartWith('[CB:CB1-abc123]');
});
