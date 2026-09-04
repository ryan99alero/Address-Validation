<?php

use App\Filament\Pages\FedExCommitmentSettings;
use App\Models\FedExCommitmentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the commitment settings page renders and persists targets + toggles', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(FedExCommitmentSettings::class)
        ->assertOk()
        ->assertSee('Commitment Targets')
        ->set('data.express_avg_daily_packages', 3.5)
        ->set('data.include_home_delivery', false)
        ->set('data.day_count_mode', 'calendar')
        ->call('saveSettings')
        ->assertHasNoErrors();

    $settings = FedExCommitmentSetting::instance();
    expect((float) $settings->express_avg_daily_packages)->toBe(3.5)
        ->and($settings->include_home_delivery)->toBeFalse()
        ->and($settings->dayCountMode())->toBe('calendar')
        // A blank target still falls back to the config default.
        ->and($settings->targets()['ground']['avg_daily_packages'])->toBe(82.10);
});
