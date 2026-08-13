<?php

use App\Filament\Resources\CarrierShipmentSummaries\CarrierShipmentSummaryResource;
use App\Filament\Resources\CarrierShipmentSummaries\Pages\ListCarrierShipmentSummaries;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function shipmentFilterFixture(): array
{
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $inv1 = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-100', 'invoice_date' => '2026-05-01', 'filename' => 'a.csv']);
    $inv2 = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-200', 'invoice_date' => '2026-05-01', 'filename' => 'b.csv']);

    $s1 = CarrierShipment::forceCreate(['carrier_invoice_id' => $inv1->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRKAAA', 'service' => 'Ground', 'ship_date' => '2026-05-01', 'zip' => '90210', 'printed_total' => 10, 'base_amount' => 8, 'fee_amount' => 2, 'is_third_party' => false, 'source_type' => 'derived']);
    $s2 = CarrierShipment::forceCreate(['carrier_invoice_id' => $inv2->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRKBBB', 'service' => 'Ground', 'ship_date' => '2026-05-01', 'zip' => '10001', 'printed_total' => 10, 'base_amount' => 8, 'fee_amount' => 2, 'is_third_party' => false, 'source_type' => 'derived']);

    // Job / customer come from carton_costs; the shipped-to address from carrier_invoice_lines — both by tracking.
    DB::table('carton_costs')->insert(['tracking_number' => 'TRKAAA', 'pace_job_number' => 'JOB-1', 'pace_customer_id' => 'CUST-9', 'U_reference' => 'PO-777', 'U_reference2' => 'REF2X', 'ship_cost' => 8, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_invoice_lines')->insert(['carrier_invoice_id' => $inv1->id, 'tracking_number' => 'TRKAAA', 'original_address_1' => '100 Rodeo Dr', 'original_city' => 'Beverly Hills', 'original_state' => 'CA', 'original_postal' => '90210', 'original_country' => 'US', 'ship_date' => '2026-05-01', 'charge_code' => 'ADC', 'charge_amount' => 2, 'created_at' => now(), 'updated_at' => now()]);

    return [$s1, $s2];
}

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

it('filters shipments by every text field in the one panel', function () {
    $this->actingAs(User::factory()->create());
    [$s1, $s2] = shipmentFilterFixture();

    foreach ([
        ['tracking', 'TRKAAA'],       // direct column
        ['invoice_number', 'INV-100'], // whereHas invoice
        ['job', 'JOB-1'],              // carton_costs exists
        ['customer', 'CUST-9'],        // carton_costs exists
        ['reference1', 'PO-777'],      // carton U_reference
        ['reference2', 'REF2X'],       // carton U_reference2
        ['address', 'Rodeo'],          // invoice line exists
        ['city', 'Beverly'],           // invoice line exists
        ['state', 'CA'],               // invoice line exists
        ['zip', '90210'],              // direct column, prefix
    ] as [$filter, $value]) {
        Livewire::test(ListCarrierShipmentSummaries::class)
            ->filterTable($filter, ['value' => $value])
            ->assertCanSeeTableRecords([$s1])
            ->assertCanNotSeeTableRecords([$s2]);
    }
});
