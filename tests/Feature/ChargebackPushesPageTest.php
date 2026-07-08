<?php

use App\Filament\Pages\ChargebackPushes;
use App\Models\ChargebackPush;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChargebackPush::create([
        'dedupe_key' => 'k1', 'carrier_id' => 1, 'tracking_number' => '1ZTEST', 'driver' => 'address_correction',
        'amount' => 20.20, 'activity_code' => '72510', 'pace_job' => 'JOB1', 'pace_jobcost_id' => '555',
        'status' => 'pushed', 'pushed_at' => now(),
    ]);
    $this->actingAs(User::factory()->create());
});

test('the chargeback pushes page lists ledger rows with the JobCost id', function () {
    Livewire::test(ChargebackPushes::class)
        ->assertOk()
        ->assertSee('1ZTEST')
        ->assertSee('72510')
        ->assertSee('555')          // the returned JobCost id
        ->assertTableActionExists('export');
});
