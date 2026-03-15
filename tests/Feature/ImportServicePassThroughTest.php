<?php

use App\Services\ImportService;

test('resolves pass-through fields to sequential extra fields', function () {
    $service = new ImportService;

    $mappings = [
        ['position' => 0, 'source' => 'Name', 'target' => 'name'],
        ['position' => 1, 'source' => 'Address', 'target' => 'address_line_1'],
        ['position' => 2, 'source' => 'Custom1', 'target' => '_passthrough'],
        ['position' => 3, 'source' => 'Custom2', 'target' => '_passthrough'],
        ['position' => 4, 'source' => 'Notes', 'target' => '_skip'],
    ];

    $resolved = $service->resolvePassThroughFields($mappings);

    expect($resolved[0]['target'])->toBe('name');
    expect($resolved[1]['target'])->toBe('address_line_1');
    expect($resolved[2]['target'])->toBe('extra_1');
    expect($resolved[3]['target'])->toBe('extra_2');
    expect($resolved[4]['target'])->toBe('_skip');
});

test('skips over explicitly mapped extra fields', function () {
    $service = new ImportService;

    $mappings = [
        ['position' => 0, 'source' => 'Name', 'target' => 'name'],
        ['position' => 1, 'source' => 'SpecialField', 'target' => 'extra_2'], // Explicitly mapped to extra_2
        ['position' => 2, 'source' => 'Custom1', 'target' => '_passthrough'],
        ['position' => 3, 'source' => 'Custom2', 'target' => '_passthrough'],
        ['position' => 4, 'source' => 'Custom3', 'target' => '_passthrough'],
    ];

    $resolved = $service->resolvePassThroughFields($mappings);

    expect($resolved[0]['target'])->toBe('name');
    expect($resolved[1]['target'])->toBe('extra_2'); // Kept as-is
    expect($resolved[2]['target'])->toBe('extra_1'); // First available
    expect($resolved[3]['target'])->toBe('extra_3'); // Skipped extra_2
    expect($resolved[4]['target'])->toBe('extra_4');
});

test('marks pass-through as skip when all extra fields are used', function () {
    $service = new ImportService;

    // Pre-fill all 20 extra fields
    $mappings = [];
    for ($i = 1; $i <= 20; $i++) {
        $mappings[] = ['position' => $i - 1, 'source' => "Explicit{$i}", 'target' => "extra_{$i}"];
    }

    // Add a pass-through that can't be assigned
    $mappings[] = ['position' => 20, 'source' => 'Overflow', 'target' => '_passthrough'];

    $resolved = $service->resolvePassThroughFields($mappings);

    // The overflow field should become _skip since no extra fields available
    expect($resolved[20]['target'])->toBe('_skip');
});

test('preserves skip targets unchanged', function () {
    $service = new ImportService;

    $mappings = [
        ['position' => 0, 'source' => 'Internal', 'target' => '_skip'],
        ['position' => 1, 'source' => 'Debug', 'target' => '_skip'],
    ];

    $resolved = $service->resolvePassThroughFields($mappings);

    expect($resolved[0]['target'])->toBe('_skip');
    expect($resolved[1]['target'])->toBe('_skip');
});
