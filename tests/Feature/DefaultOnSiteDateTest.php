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

it('fills a batch date default (on-site or ship) only when a row has none of its own', function () {
    $onSite = Carbon::parse('2026-08-15');
    $ship = Carbon::parse('2026-08-10');

    // On-Site Date: row without its own → gets the batch default; row with a file value → kept.
    expect(ProcessImportBatchImport::applyBatchDateDefault(['input_address_1' => 'x'], 'required_on_site_date', $onSite)['required_on_site_date'])->toBe('2026-08-15')
        ->and(ProcessImportBatchImport::applyBatchDateDefault(['required_on_site_date' => '2026-09-01'], 'required_on_site_date', $onSite)['required_on_site_date'])->toBe('2026-09-01');

    // Ship Date behaves identically.
    expect(ProcessImportBatchImport::applyBatchDateDefault(['input_address_1' => 'x'], 'requested_ship_date', $ship)['requested_ship_date'])->toBe('2026-08-10')
        ->and(ProcessImportBatchImport::applyBatchDateDefault(['requested_ship_date' => '2026-08-12'], 'requested_ship_date', $ship)['requested_ship_date'])->toBe('2026-08-12');

    // No batch default → nothing added.
    expect(ProcessImportBatchImport::applyBatchDateDefault(['input_address_1' => 'x'], 'required_on_site_date', null))->not->toHaveKey('required_on_site_date');
});

it('casts the batch default ship + on-site dates to dates', function () {
    $batch = new ImportBatch(['default_on_site_date' => '2026-08-15', 'default_ship_date' => '2026-08-10']);

    expect($batch->default_on_site_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($batch->default_on_site_date->toDateString())->toBe('2026-08-15')
        ->and($batch->default_ship_date->toDateString())->toBe('2026-08-10');
});

it('renders the batch import form with the new On-Site Date field', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(BatchProcessing::class)->assertOk();
});
