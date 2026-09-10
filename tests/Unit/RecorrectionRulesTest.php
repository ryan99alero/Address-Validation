<?php

use App\Services\Invoices\RecorrectionRules;

test('a dropped trailing suite with same city/state/ZIP is a fee-free reformat', function () {
    expect(RecorrectionRules::isFeeFreeReformat(
        ['address_1' => '405 babcock blvd w ste110', 'city' => 'phoenix', 'state' => 'AZ', 'postal' => '85001'],
        ['address_1' => '405 babcock blvd w', 'city' => 'phoenix', 'state' => 'AZ', 'postal' => '85001-1234'],
    ))->toBeTrue();
});

test('an E->W directional change is NOT fee-free (real correction)', function () {
    expect(RecorrectionRules::isFeeFreeReformat(
        ['address_1' => '1000 e pancake blvd', 'city' => 'liberal', 'state' => 'KS', 'postal' => '67901'],
        ['address_1' => '1000 w pancake blvd', 'city' => 'liberal', 'state' => 'KS', 'postal' => '67901'],
    ))->toBeFalse();
});

test('an ADDED unit is NOT fee-free', function () {
    expect(RecorrectionRules::isFeeFreeReformat(
        ['address_1' => '1145 county rd', 'city' => 'cullman', 'state' => 'AL', 'postal' => '35055'],
        ['address_1' => '1145 county rd 437', 'city' => 'cullman', 'state' => 'AL', 'postal' => '35055'],
    ))->toBeFalse();
});

test('a different city/ZIP is NOT fee-free even if a unit was dropped', function () {
    expect(RecorrectionRules::isFeeFreeReformat(
        ['address_1' => '100 main st 5', 'city' => 'aville', 'state' => 'TX', 'postal' => '70001'],
        ['address_1' => '100 main st', 'city' => 'bville', 'state' => 'TX', 'postal' => '70002'],
    ))->toBeFalse();
});

test('sanitizeDate recovers 2-digit years and nulls the implausible', function () {
    expect(RecorrectionRules::sanitizeDate('0020-11-07'))->toBe('2020-11-07')
        ->and(RecorrectionRules::sanitizeDate('2013-10-15'))->toBe('2013-10-15')
        ->and(RecorrectionRules::sanitizeDate(null))->toBeNull()
        ->and(RecorrectionRules::sanitizeDate(''))->toBeNull()
        ->and(RecorrectionRules::sanitizeDate('0001-01-01'))->toBeNull(); // -> 2001, still pre-2005
});
