<?php

use App\Filament\Widgets\CostIntelligenceStats;
use App\Filament\Widgets\RecoupStats;
use Livewire\Livewire;

test('cost intelligence stats render the click-to-expand affordance', function () {
    Livewire::test(CostIntelligenceStats::class, ['pageFilters' => ['year' => 0, 'month' => 0]])
        ->assertOk()
        ->assertSee('Cost Intelligence')
        ->assertSee('Click to expand');
});

test('recoup stats render the click-to-expand affordance', function () {
    Livewire::test(RecoupStats::class)
        ->assertOk()
        ->assertSee('Recoup')
        ->assertSee('Click to expand');
});
