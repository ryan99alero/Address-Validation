<?php

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

test('the human notes token is the txn_id, not a DB row id', function () {
    $notes = (new ChargebackPusher)->buildNotes('CB1-abc123', ['carrier' => 'UPS', 'label' => 'Address Correction']);

    expect($notes)->toStartWith('[CB:CB1-abc123]');
});
