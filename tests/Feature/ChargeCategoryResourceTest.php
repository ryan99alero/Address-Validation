<?php

use App\Filament\Resources\ChargeCategories\Pages\EditChargeCategory;
use App\Filament\Resources\ChargeCategories\Pages\ListChargeCategories;
use App\Models\ChargeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChargeCategory::create(['name' => 'Fuel Surcharge', 'abbreviation' => 'FUEL']);
    ChargeCategory::create(['name' => 'Address Correction', 'abbreviation' => 'ADC']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

test('the fee categories list shows Fuel Surcharge', function () {
    Livewire::test(ListChargeCategories::class)
        ->assertOk()
        ->assertSee('Fuel Surcharge')
        ->assertSee('Address Correction');
});

test('an operator can assign a Pace cost center to a category', function () {
    $fuel = ChargeCategory::where('name', 'Fuel Surcharge')->first();

    Livewire::test(EditChargeCategory::class, ['record' => $fuel->getRouteKey()])
        ->assertOk()
        ->fillForm(['pace_cost_center' => 'CC-FUEL-100'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fuel->refresh()->pace_cost_center)->toBe('CC-FUEL-100');
});
