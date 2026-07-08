<?php

use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the sidebar footer renders the environment indicator', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $host = gethostname() ?: 'unknown-host';

    $this->get(CarrierChargeResource::getUrl('index'))
        ->assertOk()
        ->assertSee($host)                 // hostname line
        ->assertSee(app()->environment()); // env label (non-production shows amber)
});
