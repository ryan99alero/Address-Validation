<?php

use App\Models\Address;
use App\Services\Carriers\FedExCarrier;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Invoke the protected formatAddressForRequest method.
 *
 * @return array<string, mixed>
 */
function formatFedExAddress(Address $address): array
{
    $carrier = new FedExCarrier;
    $method = new ReflectionMethod($carrier, 'formatAddressForRequest');

    return $method->invoke($carrier, $address);
}

test('trims whitespace-padded fields so FedEx does not reject them', function () {
    // Reproduces the import data that caused STATEORPROVINCECODE.TOO.LONG (400)
    $address = new Address([
        'input_address_1' => '2497 OKEECHOBEE BLVD. ',
        'input_address_2' => null,
        'input_city' => 'WEST PALM BEACH  ',
        'input_state' => '  FL  ',
        'input_postal' => '33409 ',
        'input_country' => 'US',
    ]);

    $formatted = formatFedExAddress($address);

    expect($formatted['stateOrProvinceCode'])->toBe('FL');
    expect($formatted['city'])->toBe('WEST PALM BEACH');
    expect($formatted['postalCode'])->toBe('33409');
    expect($formatted['streetLines'])->toBe(['2497 OKEECHOBEE BLVD.']);
    expect($formatted['countryCode'])->toBe('US');
});

test('drops empty street lines after trimming', function () {
    $address = new Address([
        'input_address_1' => '4125 Debarr Rd',
        'input_address_2' => '   ',
        'input_city' => 'Anchorage',
        'input_state' => 'AK',
        'input_postal' => '99504',
        'input_country' => 'US',
    ]);

    $formatted = formatFedExAddress($address);

    expect($formatted['streetLines'])->toBe(['4125 Debarr Rd']);
});

test('defaults country to US when missing', function () {
    $address = new Address([
        'input_address_1' => '101 East North Street',
        'input_city' => 'Kendallville',
        'input_state' => 'IN',
        'input_postal' => '46755',
        'input_country' => null,
    ]);

    $formatted = formatFedExAddress($address);

    expect($formatted['countryCode'])->toBe('US');
});
