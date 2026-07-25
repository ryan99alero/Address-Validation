<?php

use App\Filament\Pages\ChargebackPushes;
use App\Models\CartonCost;
use App\Models\ChargebackPush;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the cartonCost relationship resolves the U_reference fields by tracking number', function () {
    CartonCost::create([
        'tracking_number' => '1ZREF', 'ship_cost' => 5.00,
        'U_reference' => 'P17472', 'U_reference2' => '252006', 'U_reference3' => null,
    ]);

    $cb = ChargebackPush::create([
        'dedupe_key' => 'r1', 'carrier_id' => 1, 'tracking_number' => '1ZREF',
        'amount' => 20.20, 'status' => 'pushed', 'pace_jobcost_id' => '900',
    ]);

    expect($cb->cartonCost->U_reference)->toBe('P17472')
        ->and($cb->cartonCost->U_reference2)->toBe('252006');
});

test('the chargeback view surfaces the reference fields from the carton mirror', function () {
    CartonCost::create(['tracking_number' => '1ZREF', 'ship_cost' => 5.00, 'U_reference' => 'P17472', 'U_reference2' => '252006']);
    ChargebackPush::create(['dedupe_key' => 'r1', 'carrier_id' => 1, 'tracking_number' => '1ZREF', 'amount' => 20.20, 'status' => 'pushed', 'pace_jobcost_id' => '900']);
    $this->actingAs(User::factory()->create());

    Livewire::test(ChargebackPushes::class)
        ->assertOk()
        ->assertSee('P17472')    // Reference (visible by default)
        ->assertSee('252006');   // Reference 2 (job number)
});
