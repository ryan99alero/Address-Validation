<?php

use App\Filament\Pages\BatchProcessing;
use App\Jobs\ProcessImportBatchImport;
use App\Models\ImportBatch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('fills the batch On-Site Date only when a row has none of its own', function () {
    $default = Carbon::parse('2026-08-15');

    // Row without its own date → gets the batch-wide default.
    $filled = ProcessImportBatchImport::applyDefaultOnSiteDate(['input_address_1' => 'x'], $default);
    expect($filled['required_on_site_date'])->toBe('2026-08-15');

    // Row with its own file date → the file value wins (unchanged).
    $kept = ProcessImportBatchImport::applyDefaultOnSiteDate(['required_on_site_date' => '2026-09-01'], $default);
    expect($kept['required_on_site_date'])->toBe('2026-09-01');

    // No batch default → nothing is added.
    $none = ProcessImportBatchImport::applyDefaultOnSiteDate(['input_address_1' => 'x'], null);
    expect($none)->not->toHaveKey('required_on_site_date');
});

it('casts the batch default_on_site_date to a date', function () {
    $batch = new ImportBatch(['default_on_site_date' => '2026-08-15']);

    expect($batch->default_on_site_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($batch->default_on_site_date->toDateString())->toBe('2026-08-15');
});

it('renders the batch import form with the new On-Site Date field', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(BatchProcessing::class)->assertOk();
});
