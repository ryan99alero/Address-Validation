<?php

use App\Filament\Pages\ValidateAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the country list is a real ISO catalog: 2-letter codes -> names, incl. non-US/CA', function () {
    $countries = config('countries');

    expect($countries)->toBeArray()
        ->toHaveKey('US', 'United States')
        ->toHaveKey('CA', 'Canada')
        ->toHaveKey('DE', 'Germany')
        ->toHaveKey('GB', 'United Kingdom')
        ->and(count($countries))->toBeGreaterThan(200);

    // Every key is a 2-letter ISO code (what UPS/FedEx expect); every value is a display name.
    foreach ($countries as $code => $name) {
        expect($code)->toMatch('/^[A-Z]{2}$/')->and($name)->not->toBe($code);
    }
});

test('the validate-address page renders with the searchable country field', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ValidateAddress::class)
        ->assertOk()
        ->assertSee('Country');
});
