<?php

use App\Filament\Pages\BatchProcessing;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders the Batch Processing page with the BestWay ship-account field', function () {
    actingAs(User::factory()->create(['is_admin' => true]));
    Livewire::test(BatchProcessing::class)->assertOk();
});
