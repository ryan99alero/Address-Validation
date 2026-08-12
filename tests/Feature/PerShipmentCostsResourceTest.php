<?php

use App\Filament\Resources\CarrierShipmentSummaries\CarrierShipmentSummaryResource;
use App\Filament\Resources\CarrierShipmentSummaries\Pages\ListCarrierShipmentSummaries;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the standalone Per-Shipment Costs list off carrier_shipments', function () {
    $this->actingAs(User::factory()->create());
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id, 'invoice_number' => 'F1',
        'invoice_date' => '2026-05-07', 'filename' => 'f.csv',
    ]);
    CarrierShipment::forceCreate([
        'carrier_invoice_id' => $invoice->id, 'carrier_id' => $carrier->id,
        'tracking_number' => 'TRK00099', 'service' => 'FedEx Ground', 'ship_date' => '2026-05-01',
        'printed_total' => 50, 'base_amount' => 40, 'fee_amount' => 10, 'fee_abbrevs' => 'FUEL',
        'is_third_party' => false, 'source_type' => 'derived',
    ]);

    Livewire::test(ListCarrierShipmentSummaries::class)
        ->assertOk()
        ->assertSee('TRK00099')
        ->assertSee('FedEx');
});

it('is surfaced in the Carrier Costs nav as "All Shipments"', function () {
    expect(CarrierShipmentSummaryResource::shouldRegisterNavigation())->toBeTrue()
        ->and(CarrierShipmentSummaryResource::getNavigationLabel())->toBe('All Shipments');
});
