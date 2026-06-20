<?php

use App\Models\Address;
use App\Models\AddressCandidate;

test('an address can hold multiple candidate corrections', function () {
    $address = Address::factory()->create([
        'validation_status' => Address::STATUS_NEEDS_REVIEW,
    ]);

    $address->candidates()->createMany([
        [
            'source' => AddressCandidate::SOURCE_INVOICE_DB,
            'address_1' => '123 Main St',
            'city' => 'Memphis',
            'state' => 'TN',
            'postal' => '38103',
        ],
        [
            'source' => AddressCandidate::SOURCE_FEDEX_API,
            'address_1' => '123 Main Street',
            'city' => 'Memphis',
            'state' => 'TN',
            'postal' => '38103',
        ],
    ]);

    expect($address->candidates)->toHaveCount(2);
    expect($address->candidates->pluck('source'))
        ->toContain('invoice_db', 'fedex_api');
});

test('candidates are cascade-deleted with their address', function () {
    $address = Address::factory()->create();
    $address->candidates()->create([
        'source' => AddressCandidate::SOURCE_UPS_API,
        'address_1' => '1 Infinite Loop',
        'city' => 'Cupertino',
        'state' => 'CA',
        'postal' => '95014',
    ]);

    expect(AddressCandidate::count())->toBe(1);

    $address->delete();

    expect(AddressCandidate::count())->toBe(0);
});

test('issues scope returns invalid, ambiguous, and needs_review addresses only', function () {
    Address::factory()->create(['validation_status' => Address::STATUS_VALID]);
    Address::factory()->create(['validation_status' => Address::STATUS_PENDING]);
    Address::factory()->create(['validation_status' => Address::STATUS_INVALID]);
    Address::factory()->create(['validation_status' => Address::STATUS_AMBIGUOUS]);
    Address::factory()->create(['validation_status' => Address::STATUS_NEEDS_REVIEW]);

    $statuses = Address::issues()->pluck('validation_status');

    expect($statuses)->toHaveCount(3);
    expect($statuses)->toContain('invalid', 'ambiguous', 'needs_review');
    expect($statuses)->not->toContain('valid', 'pending');
});

test('needs_review status persists now that validation_status is a string', function () {
    $address = Address::factory()->create([
        'validation_status' => Address::STATUS_NEEDS_REVIEW,
    ]);

    expect($address->fresh()->validation_status)->toBe('needs_review');
});

test('source label reflects the candidate origin', function () {
    $candidate = new AddressCandidate(['source' => AddressCandidate::SOURCE_INVOICE_DB]);

    expect($candidate->source_label)->toBe('Invoice DB');
});

test('choosing a candidate applies it to the address and purges the rest', function () {
    $address = Address::factory()->create([
        'validation_status' => Address::STATUS_NEEDS_REVIEW,
        'validation_source' => null,
        'output_address_1' => null,
    ]);

    $address->candidates()->create([
        'source' => AddressCandidate::SOURCE_INVOICE_DB,
        'address_1' => '123 Main Street',
        'city' => 'Memphis',
        'state' => 'TN',
        'postal' => '38103',
    ]);
    $chosen = $address->candidates()->create([
        'source' => AddressCandidate::SOURCE_FEDEX_API,
        'address_1' => '999 Different Ave',
        'city' => 'Memphis',
        'state' => 'TN',
        'postal' => '38103',
        'confidence_score' => 95.0,
    ]);

    $chosen->choose();

    $fresh = $address->fresh();
    expect($fresh->validation_status)->toBe('valid');
    expect($fresh->validation_source)->toBe(Address::SOURCE_FEDEX_API);
    expect($fresh->output_address_1)->toBe('999 Different Ave');
    expect($fresh->candidates()->count())->toBe(0);
});
