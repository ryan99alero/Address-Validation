<?php

use App\Filament\Resources\ShipViaCodes\Pages\CreateShipViaCode;
use App\Models\Carrier;
use App\Models\ShipViaCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The same "Your Code" may map to several ship-via options (e.g. a sender-paid and a third-party
 * version, or Ground and Home Delivery) — that's how the shipping process works. The form must not
 * block a duplicate code (the DB code index is intentionally non-unique).
 */
it('allows creating a ship-via with a code that already exists', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);

    // Existing sender-paid FedEx Ground on code 5138.
    ShipViaCode::create([
        'code' => '5138', 'carrier_id' => $fedex->id, 'service_type' => 'FEDEX_GROUND',
        'service_name' => 'FedEx Ground', 'payment_type' => 'sender', 'is_active' => true,
    ]);

    // Add a second option on the SAME code (e.g. a third-party Home Delivery) — must be accepted.
    Livewire::test(CreateShipViaCode::class)
        ->fillForm([
            'code' => '5138',
            'service_name' => 'FedEx Home Delivery',
            'payment_type' => 'third_party',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ShipViaCode::where('code', '5138')->count())->toBe(2);
});
