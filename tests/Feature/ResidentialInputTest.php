<?php

use App\Models\Address;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-maps a Residential / Address Type / RDI column', function () {
    $target = fn (string $h): ?string => collect(app(ImportService::class)->autoMatchHeaders([$h]))
        ->firstWhere('source', $h)['target'];

    expect($target('Residential'))->toBe('input_is_residential')
        ->and($target('Address Type'))->toBe('input_is_residential')
        ->and($target('RDI'))->toBe('input_is_residential');
});

it('parses residential file values into booleans', function () {
    expect(Address::parseResidential('Residential'))->toBeTrue()
        ->and(Address::parseResidential('R'))->toBeTrue()
        ->and(Address::parseResidential('Yes'))->toBeTrue()
        ->and(Address::parseResidential('Commercial'))->toBeFalse()
        ->and(Address::parseResidential('C'))->toBeFalse()
        ->and(Address::parseResidential('No'))->toBeFalse()
        ->and(Address::parseResidential(''))->toBeNull()
        ->and(Address::parseResidential(null))->toBeNull()
        ->and(Address::parseResidential('maybe'))->toBeNull();
});

it('stores a raw residential value via the mutator (No must not become true)', function () {
    $commercial = Address::factory()->create(['input_address_1' => '1 Main', 'input_is_residential' => 'No']);
    expect($commercial->fresh()->input_is_residential)->toBeFalse();

    $residential = Address::factory()->create(['input_address_1' => '2 Main', 'input_is_residential' => 'Residential']);
    expect($residential->fresh()->input_is_residential)->toBeTrue();
});
