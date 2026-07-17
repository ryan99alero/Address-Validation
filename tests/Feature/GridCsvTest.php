<?php

use App\Filament\Resources\CarrierAccounts\Pages\ListCarrierAccounts;
use App\Filament\Resources\Plants\Pages\ListPlants;
use App\Filament\Resources\ShipViaCodes\Pages\ListShipViaCodes;
use App\Models\Plant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    actingAs(User::factory()->create(['is_admin' => true]));
});

it('adds Import/Export header actions to grids without breaking render', function () {
    $pages = [
        ListCarrierAccounts::class, // resets toolbarActions → injected in the table class
        ListPlants::class,
        ListShipViaCodes::class,
        \App\Filament\Resources\Carriers\Pages\ListCarriers::class, // no toolbarActions → global push
    ];
    foreach ($pages as $page) {
        Livewire::test($page)
            ->assertOk()
            ->assertTableActionExists('exportCsv')
            ->assertTableActionExists('importCsv');
    }
});

it('exports the grid to CSV', function () {
    Plant::create(['code' => 'PLANT001', 'name' => 'Wichita']);

    Livewire::test(ListPlants::class)
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();
});

it('imports CSV rows into the grid model (create + update by primary key)', function () {
    $existing = Plant::create(['code' => 'PLANT001', 'name' => 'Old Name']);

    $csv = "id,code,name,is_active\n"
        ."{$existing->id},PLANT001,Wichita,1\n"      // update existing
        .",PLANT009,New Plant,1\n";                  // create new

    $file = UploadedFile::fake()->createWithContent('plants.csv', $csv);

    Livewire::test(ListPlants::class)
        ->callTableAction('importCsv', data: ['file' => $file]);

    expect(Plant::find($existing->id)->name)->toBe('Wichita')          // updated
        ->and(Plant::where('code', 'PLANT009')->first()?->name)->toBe('New Plant'); // created
});
