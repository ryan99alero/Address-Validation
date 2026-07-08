<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ImportActivityStats;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the import activity widget renders its queue stats', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(ImportActivityStats::class)
        ->assertOk()
        ->assertSee('Processing now')
        ->assertSee('Queued')
        ->assertSee('Failed');
});

test('the import activity widget is registered on the dashboard', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSeeLivewire(ImportActivityStats::class);
});
