<?php

use App\Filament\Resources\ChargeDrivers\Pages\EditChargeDriver;
use App\Filament\Resources\ChargeDrivers\Pages\ListChargeDrivers;
use App\Models\ChargeDriver;
use App\Models\User;
use Database\Seeders\ChargeDriverSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ChargeDriverSeeder::class);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

test('the chargeback codes list renders the seeded drivers', function () {
    Livewire::test(ListChargeDrivers::class)
        ->assertOk()
        ->assertSee('Address Correction')
        ->assertSee('DIM / Weight Audit');
});

test('an operator can set the Pace code and push flag on a driver', function () {
    $driver = ChargeDriver::where('key', 'address_correction')->first();

    Livewire::test(EditChargeDriver::class, ['record' => $driver->getRouteKey()])
        ->assertOk()
        ->fillForm(['pace_activity_code' => 'ADRC-01', 'push_to_pace' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($driver->refresh())
        ->pace_activity_code->toBe('ADRC-01')
        ->push_to_pace->toBeTrue();
});
