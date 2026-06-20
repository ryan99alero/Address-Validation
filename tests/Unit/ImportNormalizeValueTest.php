<?php

use App\Services\ImportService;

test('trims leading and trailing whitespace', function () {
    expect(ImportService::normalizeImportValue('  FL  '))->toBe('FL');
    expect(ImportService::normalizeImportValue('WEST PALM BEACH  '))->toBe('WEST PALM BEACH');
    expect(ImportService::normalizeImportValue("\tAnchorage\n"))->toBe('Anchorage');
});

test('collapses internal runs of whitespace to single spaces', function () {
    expect(ImportService::normalizeImportValue('123   Main    St'))->toBe('123 Main St');
    expect(ImportService::normalizeImportValue("New\tYork"))->toBe('New York');
});

test('reduces all-whitespace strings to empty so they are skipped on import', function () {
    expect(ImportService::normalizeImportValue('   '))->toBe('');
});

test('leaves non-string values unchanged', function () {
    expect(ImportService::normalizeImportValue(null))->toBeNull();
    expect(ImportService::normalizeImportValue(33409))->toBe(33409);
    expect(ImportService::normalizeImportValue(12.5))->toBe(12.5);
});
