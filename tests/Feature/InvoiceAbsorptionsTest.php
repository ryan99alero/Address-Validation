<?php

use App\Filament\Pages\ChargebackPushes;
use App\Filament\Resources\CarrierInvoices\Pages\ViewCarrierInvoice;
use App\Filament\Resources\CarrierInvoices\RelationManagers\ChargebackPushesRelationManager;
use App\Filament\Resources\CarrierInvoices\RelationManagers\ShipmentsRelationManager;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeInvoice(): CarrierInvoice
{
    $carrier = Carrier::factory()->create();

    return CarrierInvoice::create([
        'carrier_id' => $carrier->id,
        'invoice_number' => 'INV-ABS-1',
        'invoice_date' => now()->toDateString(),
    ]);
}

test('the absorbed relation managers load and query against an invoice', function () {
    $invoice = makeInvoice();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    // Directly exercise each relation manager (runs its table SQL) — they are lazy tabs on the
    // view page, so only the active one appears in the initial page HTML.
    Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewCarrierInvoice::class])
        ->assertOk();
    Livewire::test(ChargebackPushesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewCarrierInvoice::class])
        ->assertOk();
});

test('the invoice view page shows the new relation-manager tabs', function () {
    $invoice = makeInvoice();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(ViewCarrierInvoice::getUrl(['record' => $invoice]))
        ->assertOk()
        ->assertSee('Per-Shipment Costs')
        ->assertSee('Chargeback Pushes');
});

test('the global chargeback ledger stays reachable and keeps its CSV export', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(ChargebackPushes::getUrl())
        ->assertOk()
        ->assertSee('Export CSV');
});

test('absorbed pages are removed from the sidebar navigation', function () {
    expect(CarrierInvoice::class)->toBeString(); // anchor
    expect(\App\Filament\Resources\CarrierShipmentSummaries\CarrierShipmentSummaryResource::shouldRegisterNavigation())->toBeFalse();
    expect(ChargebackPushes::shouldRegisterNavigation())->toBeFalse();
});
