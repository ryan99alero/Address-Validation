<?php

use App\Services\Invoices\AddressCorrectionAnalyzer;

beforeEach(function () {
    $this->analyzer = new AddressCorrectionAnalyzer;
});

test('abbreviation/punctuation-only changes are formatting_only with score 0', function () {
    $result = $this->analyzer->analyze(
        ['address_1' => '1710 WEST TENNESSEE STREET', 'city' => 'TALLAHASSEE', 'state' => 'FL', 'postal' => '32304'],
        ['address_1' => '1710 W TENNESSEE ST', 'city' => 'TALLAHASSEE', 'state' => 'FL', 'postal' => '32304'],
    );

    expect($result['severity_score'])->toBe(0)
        ->and($result['severity_category'])->toBe('formatting_only')
        ->and($result['change_type'])->toBe('formatting_only');
});

test('a changed 5-digit zip is classified zip_changed', function () {
    $result = $this->analyzer->analyze(
        ['address_1' => '1201 SANTEE ST', 'city' => 'LOS ANGELES', 'state' => 'CA', 'postal' => '90014'],
        ['address_1' => '1201 SANTEE ST', 'city' => 'LOS ANGELES', 'state' => 'CA', 'postal' => '90015'],
    );

    expect($result['change_type'])->toBe('zip_changed')
        ->and($result['severity_score'])->toBeGreaterThan(0);
});

test('zip +4 extension alone does not count as a zip change', function () {
    $result = $this->analyzer->analyze(
        ['address_1' => '500 S FLORENCE ST', 'city' => 'WICHITA', 'state' => 'KS', 'postal' => '67209'],
        ['address_1' => '500 S FLORENCE ST', 'city' => 'WICHITA', 'state' => 'KS', 'postal' => '67209-2501'],
    );

    expect($result['change_type'])->not->toBe('zip_changed');
});

test('a changed house number is street_number_changed', function () {
    $result = $this->analyzer->analyze(
        ['address_1' => '123 MAIN ST', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701'],
        ['address_1' => '125 MAIN ST', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701'],
    );

    expect($result['change_type'])->toBe('street_number_changed');
});

test('a different street name is street_renamed', function () {
    $result = $this->analyzer->analyze(
        ['address_1' => '123 MAIN ST', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701'],
        ['address_1' => '123 OAK ST', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701'],
    );

    expect($result['change_type'])->toBe('street_renamed');
});

test('adding a suite is suite_changed', function () {
    $result = $this->analyzer->analyze(
        ['address_1' => '100 INNOVATION WAY', 'address_2' => '', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701'],
        ['address_1' => '100 INNOVATION WAY', 'address_2' => 'STE 200', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701'],
    );

    expect($result['change_type'])->toBe('suite_changed');
});

test('normalize canonicalizes directionals and suffixes', function () {
    expect($this->analyzer->normalize('1710 West Tennessee Street.'))
        ->toBe('1710 W TENNESSEE ST');
});
