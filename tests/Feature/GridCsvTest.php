<?php

use App\Filament\Resources\CarrierAccounts\Pages\ListCarrierAccounts;
use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Filament\Resources\Plants\Pages\ListPlants;
use App\Filament\Resources\ShipViaCodes\Pages\ListShipViaCodes;
use App\Models\Plant;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    actingAs(User::factory()->create(['is_admin' => true]));
});

it('renders the Import/Export dropdown inline in every grid toolbar (by Filters/Fields)', function () {
    // Grids that set their own toolbarActions (ShipViaCodes/CarrierAccounts/Plants) and ones
    // that don't (Carriers) — the trigger is a render hook at the toolbar end, independent of both.
    foreach ([ListCarrierAccounts::class, ListPlants::class, ListShipViaCodes::class, ListCarriers::class] as $page) {
        Livewire::test($page)
            ->assertOk()
            ->assertSee('Import CSV')
            ->assertSee('Export CSV');
    }
});

it('mounts the hidden, registered actions — the real toolbar wire:click path', function () {
    Plant::create(['code' => 'PLANT001', 'name' => 'Wichita']);

    // call() hits mountTableAction directly (as the dropdown's wire:click does), bypassing the
    // test helper's visibility check — proving the hidden actions resolve + mount from the UI.
    // (mountTableAction delegates to mountAction, which does not check visibility.)
    Livewire::test(ListPlants::class)
        ->call('mountTableAction', 'exportCsv')
        ->assertHasNoErrors()
        ->call('mountTableAction', 'importCsv')
        ->assertHasNoErrors();
});
