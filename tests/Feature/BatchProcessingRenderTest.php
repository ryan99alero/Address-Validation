<?php

use App\Filament\Pages\BatchProcessing;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders the Batch Processing page with the BestWay ship-account field', function () {
    actingAs(User::factory()->create(['is_admin' => true]));
    Livewire::test(BatchProcessing::class)->assertOk();
});

it('groups the import form into task sections', function () {
    actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(BatchProcessing::class)
        ->assertOk()
        ->assertSee('Upload File')
        ->assertSee('Validation Options')
        ->assertSee('Import Name')
        ->assertSee('Validation Engine')
        // The Transit / BestWay block only appears once "Include Time in Transit" is ticked.
        ->assertDontSee('Transit Time & BestWay');
});

it('groups the export form into task sections', function () {
    actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(BatchProcessing::class)
        ->set('activeTab', 'export')
        ->assertOk()
        ->assertSee('Source & Format')
        ->assertSee('Filters & Output');
});
