<?php

use App\Filament\Pages\ChargebackPushes;
use App\Filament\Resources\CarrierInvoices\Pages\ListCarrierInvoices;
use App\Models\ChargebackPush;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the global grid export writes the filtered set to storage + notifies (custom page)', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    ChargebackPush::create(['dedupe_key' => 'd', 'carrier_id' => 1, 'tracking_number' => 'T', 'amount' => 5, 'status' => 'pushed', 'activity_code' => '72510']);

    Livewire::test(ChargebackPushes::class)
        ->call('mountTableAction', 'exportCsv')
        ->assertNotified('Export ready');

    expect(Storage::disk('local')->files('exports'))->toHaveCount(1);
});

test('the global grid export works on a Resource list page (CarrierInvoices)', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(ListCarrierInvoices::class)
        ->call('mountTableAction', 'exportCsv')
        ->assertNotified('Export ready');

    expect(Storage::disk('local')->files('exports'))->toHaveCount(1);
});

test('the grid export can produce an XLSX (Excel) file', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    ChargebackPush::create(['dedupe_key' => 'd', 'carrier_id' => 1, 'tracking_number' => 'T', 'amount' => 5, 'status' => 'pushed', 'activity_code' => '72510']);

    Livewire::test(ChargebackPushes::class)
        ->call('mountTableAction', 'exportXlsx')
        ->assertNotified('Export ready');

    $files = Storage::disk('local')->files('exports');
    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.xlsx');
});
