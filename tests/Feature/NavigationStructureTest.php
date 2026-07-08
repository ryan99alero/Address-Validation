<?php

use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Renders a real panel page over HTTP (not Livewire::test, which skips the layout) so the FULL
 * sidebar navigation tree is built and asserted — this is the web path, and it catches any
 * navigationGroup type/reference error that a component-only test would miss.
 */
test('sidebar is organized into the four target groups with renamed items', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(CarrierChargeResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Address Intelligence')
        ->assertSee('Carrier Costs')
        ->assertSee('Configuration')
        ->assertSee('Admin')
        ->assertSee('Correction Cache')   // renamed from "Address Corrections"
        ->assertSee('Pace Corrections');  // renamed from "Pace Address Corrections"
});

test('non-admin does not see admin-only groups in the sidebar', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get(CarrierChargeResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Carrier Costs')        // operator-visible group
        ->assertDontSee('LDAP Settings')    // Configuration item, admin-gated
        ->assertDontSee('Failed Jobs');     // Admin group, admin-gated
});
