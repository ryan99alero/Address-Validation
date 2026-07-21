<?php

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Jobs\ProcessImportBatchValidation;
use App\Models\Address;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// FIX 1 — ZIP+4 no longer reported as a false "Zip fix".
test('a ZIP+4 input matching the split ZIP5+ext output is NOT reported as a Zip change', function () {
    $a = new Address([
        'input_address_1' => '1 MAIN ST', 'output_address_1' => '1 MAIN ST',
        'input_city' => 'WICHITA', 'output_city' => 'WICHITA',
        'input_state' => 'KS', 'output_state' => 'KS',
        'input_postal' => '67215-1234', 'output_postal' => '67215', 'output_postal_ext' => '1234',
    ]);

    expect($a->change_summary)->not->toContain('Zip');
});

test('a real ZIP change is still reported, with the full 5+4 value', function () {
    $a = new Address([
        'input_address_1' => '1 MAIN ST', 'output_address_1' => '1 MAIN ST',
        'input_city' => 'WICHITA', 'output_city' => 'WICHITA', 'input_state' => 'KS', 'output_state' => 'KS',
        'input_postal' => '67215', 'output_postal' => '67212', 'output_postal_ext' => '6789',
    ]);

    expect($a->change_summary)->toContain('Zip: 67212-6789');
});

// FIX 2 — BOTH BestWay flags are a real Yes/No, never blank, in a find_best_service batch.
test('BestWay optimization defaults unprocessed addresses to No on both flags', function () {
    $batch = ImportBatch::create(['name' => 'B', 'original_filename' => 'b.csv', 'file_path' => 'b.csv', 'status' => 'processing', 'find_best_service' => true]);
    // Two addresses with no required_on_site_date / transit times → BestWay can't evaluate them.
    $a1 = Address::create(['import_batch_id' => $batch->id, 'validation_status' => 'valid']);
    $a2 = Address::create(['import_batch_id' => $batch->id, 'validation_status' => 'valid']);
    expect($a1->bestway_optimized)->toBeNull()
        ->and($a1->ship_via_meets_deadline)->toBeNull();

    (fn () => $this->applyBestWayOptimization())->call(new ProcessImportBatchValidation($batch));

    expect($a1->refresh()->bestway_optimized)->toBeFalse()
        ->and($a1->ship_via_meets_deadline)->toBeFalse()
        ->and($a2->refresh()->bestway_optimized)->toBeFalse()
        ->and($a2->ship_via_meets_deadline)->toBeFalse();
});

// FIX 3 — non-admins see only their own batches; admins see all.
test('admins see all import batches, non-admins only their own', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['is_admin' => false]);
    ImportBatch::create(['name' => 'mine', 'original_filename' => 'a.csv', 'file_path' => 'a.csv', 'status' => 'completed', 'imported_by' => $user->id]);
    ImportBatch::create(['name' => 'theirs', 'original_filename' => 'b.csv', 'file_path' => 'b.csv', 'status' => 'completed', 'imported_by' => $admin->id]);

    $this->actingAs($admin);
    expect(ImportBatchResource::getEloquentQuery()->count())->toBe(2);

    $this->actingAs($user);
    expect(ImportBatchResource::getEloquentQuery()->count())->toBe(1);
});

// FIX 4 — the Import Batches list shows the batch name.
test('the Import Batches list shows the batch name', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    ImportBatch::create(['name' => 'Master Ship List July', 'original_filename' => 'msl.csv', 'file_path' => 'msl.csv', 'status' => 'completed']);

    Livewire::test(ListImportBatches::class)
        ->assertOk()
        ->assertSee('Master Ship List July');
});
