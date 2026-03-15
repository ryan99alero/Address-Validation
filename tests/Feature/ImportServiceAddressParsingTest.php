<?php

use App\Services\ImportService;

test('extracts hash unit number from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => 'Exchange Building #341',
    ]);

    expect($result['address_line_1'])->toBe('Exchange Building');
    expect($result['address_line_2'])->toBe('STE 341');
});

test('extracts comma separated suite from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '8615 Tidwell Rd, Ste B',
    ]);

    expect($result['address_line_1'])->toBe('8615 Tidwell Rd');
    expect($result['address_line_2'])->toBe('STE B');
});

test('extracts space separated suite from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '5095 Blue Diamond Rd Ste A-7',
    ]);

    expect($result['address_line_1'])->toBe('5095 Blue Diamond Rd');
    expect($result['address_line_2'])->toBe('STE A-7');
});

test('extracts suite with ampersand from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '10722 BEVERLY BLVD STE B & C',
    ]);

    expect($result['address_line_1'])->toBe('10722 BEVERLY BLVD');
    expect($result['address_line_2'])->toBe('STE B & C');
});

test('extracts unit number from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '123 Main Street Unit 5',
    ]);

    expect($result['address_line_1'])->toBe('123 Main Street');
    expect($result['address_line_2'])->toBe('UNIT 5');
});

test('extracts apartment number from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '456 Oak Avenue Apt 12B',
    ]);

    expect($result['address_line_1'])->toBe('456 Oak Avenue');
    expect($result['address_line_2'])->toBe('APT 12B');
});

test('appends extracted unit to existing address_line_2', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '123 Main St Ste 5',
        'address_line_2' => 'Building A',
    ]);

    expect($result['address_line_1'])->toBe('123 Main St');
    expect($result['address_line_2'])->toBe('Building A, STE 5');
});

test('concatenates multiple units when both address lines have unit info', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '500 Corporate Dr #101',
        'address_line_2' => 'Floor 3',
    ]);

    expect($result['address_line_1'])->toBe('500 Corporate Dr');
    expect($result['address_line_2'])->toBe('Floor 3, STE 101');
});

test('handles address without unit designation', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '789 Pine Boulevard',
    ]);

    expect($result['address_line_1'])->toBe('789 Pine Boulevard');
    expect($result)->not->toHaveKey('address_line_2');
});

test('handles Suite keyword variations', function () {
    $service = new ImportService;

    $result1 = $service->parseAddressLine(['address_line_1' => '100 Test St Suite 101']);
    $result2 = $service->parseAddressLine(['address_line_1' => '100 Test St STE 101']);
    $result3 = $service->parseAddressLine(['address_line_1' => '100 Test St ste 101']);

    expect($result1['address_line_2'])->toBe('STE 101');
    expect($result2['address_line_2'])->toBe('STE 101');
    expect($result3['address_line_2'])->toBe('STE 101');
});

test('handles building designation', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '500 Corporate Drive Bldg 3',
    ]);

    expect($result['address_line_1'])->toBe('500 Corporate Drive');
    expect($result['address_line_2'])->toBe('BLDG 3');
});

test('handles floor designation', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '200 Tower Plaza Fl 15',
    ]);

    expect($result['address_line_1'])->toBe('200 Tower Plaza');
    expect($result['address_line_2'])->toBe('FL 15');
});

test('handles hash with alphanumeric unit', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'address_line_1' => '100 Industrial Park #5A',
    ]);

    expect($result['address_line_1'])->toBe('100 Industrial Park');
    expect($result['address_line_2'])->toBe('STE 5A');
});
