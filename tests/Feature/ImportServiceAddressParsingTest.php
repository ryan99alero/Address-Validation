<?php

use App\Services\ImportService;

test('extracts unit when preceded by keyword like Ste', function () {
    $service = new ImportService;

    // Unit keyword required for extraction
    // Address must have street number or suffix to be considered valid
    $result = $service->parseAddressLine([
        'input_address_1' => '123 Exchange Plaza Ste 341',
    ]);

    expect($result['input_address_1'])->toBe('123 Exchange Plaza');
    expect($result['input_address_2'])->toBe('STE 341');
});

test('extracts comma separated suite from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '8615 Tidwell Rd, Ste B',
    ]);

    expect($result['input_address_1'])->toBe('8615 Tidwell Rd');
    expect($result['input_address_2'])->toBe('STE B');
});

test('extracts space separated suite from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '5095 Blue Diamond Rd Ste A-7',
    ]);

    expect($result['input_address_1'])->toBe('5095 Blue Diamond Rd');
    expect($result['input_address_2'])->toBe('STE A-7');
});

test('extracts suite with ampersand from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '10722 BEVERLY BLVD STE B & C',
    ]);

    expect($result['input_address_1'])->toBe('10722 BEVERLY BLVD');
    expect($result['input_address_2'])->toBe('STE B & C');
});

test('extracts unit number from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '123 Main Street Unit 5',
    ]);

    expect($result['input_address_1'])->toBe('123 Main Street');
    expect($result['input_address_2'])->toBe('UNIT 5');
});

test('extracts apartment number from address', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '456 Oak Avenue Apt 12B',
    ]);

    expect($result['input_address_1'])->toBe('456 Oak Avenue');
    expect($result['input_address_2'])->toBe('APT 12B');
});

test('appends extracted unit to existing input_address_2', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '123 Main St Ste 5',
        'input_address_2' => 'Building A',
    ]);

    expect($result['input_address_1'])->toBe('123 Main St');
    expect($result['input_address_2'])->toBe('Building A, STE 5');
});

test('preserves address_2 when no unit extracted from address_1', function () {
    $service = new ImportService;

    // Bare # symbols are NOT extracted (could be route numbers)
    $result = $service->parseAddressLine([
        'input_address_1' => '500 Corporate Dr #101',
        'input_address_2' => 'Floor 3',
    ]);

    // Address line 1 unchanged (no unit keyword like STE, UNIT, APT)
    expect($result['input_address_1'])->toBe('500 Corporate Dr #101');
    expect($result['input_address_2'])->toBe('Floor 3');
});

test('handles address without unit designation', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '789 Pine Boulevard',
    ]);

    expect($result['input_address_1'])->toBe('789 Pine Boulevard');
    expect($result)->not->toHaveKey('input_address_2');
});

test('handles Suite keyword variations', function () {
    $service = new ImportService;

    $result1 = $service->parseAddressLine(['input_address_1' => '100 Test St Suite 101']);
    $result2 = $service->parseAddressLine(['input_address_1' => '100 Test St STE 101']);
    $result3 = $service->parseAddressLine(['input_address_1' => '100 Test St ste 101']);

    expect($result1['input_address_2'])->toBe('STE 101');
    expect($result2['input_address_2'])->toBe('STE 101');
    expect($result3['input_address_2'])->toBe('STE 101');
});

test('handles building designation', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '500 Corporate Drive Bldg 3',
    ]);

    expect($result['input_address_1'])->toBe('500 Corporate Drive');
    expect($result['input_address_2'])->toBe('BLDG 3');
});

test('handles floor designation', function () {
    $service = new ImportService;

    $result = $service->parseAddressLine([
        'input_address_1' => '200 Tower Plaza Fl 15',
    ]);

    expect($result['input_address_1'])->toBe('200 Tower Plaza');
    expect($result['input_address_2'])->toBe('FL 15');
});

test('does not extract bare hash symbols (could be route numbers)', function () {
    $service = new ImportService;

    // Bare # without unit keyword is NOT extracted
    // This prevents false positives like "1234 Route #9"
    $result = $service->parseAddressLine([
        'input_address_1' => '100 Industrial Park #5A',
    ]);

    // Address unchanged - bare # not considered unit designator
    expect($result['input_address_1'])->toBe('100 Industrial Park #5A');
    expect($result)->not->toHaveKey('input_address_2');
});
