<?php

use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the Ctrl+K command palette component renders on panel pages', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(CarrierChargeResource::getUrl('index'))
        ->assertOk()
        ->assertSeeLivewire('livewire-ui-spotlight');
});

test('key resources are enabled for global search', function () {
    expect(CarrierInvoiceResource::canGloballySearch())->toBeTrue();
    expect(CarrierInvoiceResource::getRecordTitleAttribute())->toBe('invoice_number');
});
