<?php

use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use App\Filament\Resources\CarrierCharges\Pages\ListCarrierCharges;
use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Models\ChargeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Force the base (13) + address-correction (1) category ids the auxiliary filter keys off of. */
function seedChargeCategories(): void
{
    foreach ([13 => 'Base Transportation', 1 => 'Address Correction'] as $id => $name) {
        if (! ChargeCategory::query()->whereKey($id)->exists()) {
            ChargeCategory::forceCreate(['id' => $id, 'name' => $name]);
        }
    }
}

/**
 * Two auxiliary charges that share every field except the one under test. c2's job/customer/etc.
 * CONTAIN c1's search values but aren't equal, so the exact Job/Customer filters must exclude it.
 *
 * @return array{0: CarrierCharge, 1: CarrierCharge}
 */
function chargeFilterFixture(): array
{
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    seedChargeCategories();

    $inv1 = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-100', 'invoice_date' => '2026-05-01', 'filename' => 'a.csv']);
    $inv2 = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-200', 'invoice_date' => '2026-05-01', 'filename' => 'b.csv']);

    // Both auxiliary (cat 1) so the default "auxiliary only" filter keeps both; the text filter is
    // what must isolate c1 from the near-match c2.
    $c1 = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv1->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2026-05-01', 'ship_date' => '2026-05-01', 'tracking_number' => 'TRKAAA', 'raw_charge_description' => 'Address Correction', 'charge_category_id' => 1, 'amount' => 20, 'service' => 'Ground', 'source_type' => 'csv']);
    $c2 = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv2->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2026-05-01', 'ship_date' => '2026-05-01', 'tracking_number' => 'TRKBBB', 'raw_charge_description' => 'Residential', 'charge_category_id' => 1, 'amount' => 5, 'service' => 'Express', 'source_type' => 'csv']);

    DB::table('carton_costs')->insert([
        ['tracking_number' => 'TRKAAA', 'pace_job_number' => 'JOB-1', 'pace_customer_id' => 'CUST-9', 'U_reference' => 'PO-777', 'U_reference2' => 'REF2X', 'ship_cost' => 8, 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TRKBBB', 'pace_job_number' => 'JOB-1X', 'pace_customer_id' => 'CUST-9X', 'U_reference' => 'PO-000', 'U_reference2' => 'REF2Y', 'ship_cost' => 8, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('carrier_invoice_lines')->insert(['carrier_invoice_id' => $inv1->id, 'tracking_number' => 'TRKAAA', 'original_address_1' => '100 Rodeo Dr', 'original_city' => 'Beverly Hills', 'original_state' => 'CA', 'original_postal' => '90210', 'original_country' => 'US', 'ship_date' => '2026-05-01', 'charge_code' => 'ADC', 'charge_amount' => 2, 'created_at' => now(), 'updated_at' => now()]);
    CarrierShipment::forceCreate(['carrier_invoice_id' => $inv1->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRKAAA', 'ship_date' => '2026-05-01', 'zip' => '90210', 'source_type' => 'derived']);
    CarrierShipment::forceCreate(['carrier_invoice_id' => $inv2->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRKBBB', 'ship_date' => '2026-05-01', 'zip' => '10001', 'source_type' => 'derived']);

    return [$c1, $c2];
}

it('is renamed to "All Charges" in the nav', function () {
    expect(CarrierChargeResource::getNavigationLabel())->toBe('All Charges');
});

it('filters charges by every shared text field', function () {
    $this->actingAs(User::factory()->create());
    [$c1, $c2] = chargeFilterFixture();

    foreach ([
        ['tracking', 'TRKAAA'],        // direct column
        ['invoice_number', 'INV-100'], // whereHas invoice
        ['job', 'JOB-1'],              // carton_costs exact
        ['customer', 'CUST-9'],        // carton_costs exact
        ['reference1', 'PO-777'],      // carton U_reference
        ['reference2', 'REF2X'],       // carton U_reference2
        ['address', 'Rodeo'],          // invoice line
        ['city', 'Beverly'],           // invoice line
        ['state', 'CA'],               // invoice line
        ['zip', '90210'],              // carrier_shipments by tracking
        ['service', 'Ground'],         // direct column
    ] as [$filter, $value]) {
        // cost_center_categories defaults on; the fixture charges are in a no-cost-center category,
        // so turn it off to isolate the text filter under test.
        Livewire::test(ListCarrierCharges::class)
            ->filterTable('cost_center_categories', false)
            ->filterTable($filter, ['value' => $value])
            ->assertCanSeeTableRecords([$c1])
            ->assertCanNotSeeTableRecords([$c2]);
    }
});

it('auxiliary-only is opt-in and hides base transportation', function () {
    $this->actingAs(User::factory()->create());
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    seedChargeCategories();
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-05-01', 'filename' => 'x.csv']);

    $aux = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2026-05-01', 'tracking_number' => 'AUX1', 'raw_charge_description' => 'Address Correction', 'charge_category_id' => 1, 'amount' => 20, 'source_type' => 'csv']);
    $base = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2026-05-01', 'tracking_number' => 'BASE1', 'raw_charge_description' => 'Ground Freight', 'charge_category_id' => 13, 'amount' => 100, 'source_type' => 'csv']);

    // Turn off the cost-center default (both charges are in no-cost-center categories); both show now.
    Livewire::test(ListCarrierCharges::class)
        ->filterTable('cost_center_categories', false)
        ->assertCanSeeTableRecords([$aux, $base])
        // Opt into auxiliary-only → base transport drops out.
        ->filterTable('auxiliary_only')
        ->assertCanSeeTableRecords([$aux])
        ->assertCanNotSeeTableRecords([$base]);
});

it('defaults to the Pace cost-center categories, showing each line individually', function () {
    $this->actingAs(User::factory()->create());
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    // Audit / Correction Fee carries a Pace cost center; Base Transportation does not.
    ChargeCategory::forceCreate(['id' => 10, 'name' => 'Audit / Correction Fee', 'pace_cost_center' => '72530']);
    ChargeCategory::forceCreate(['id' => 13, 'name' => 'Base Transportation']);
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-05-01', 'filename' => 'x.csv']);

    // Two separate lines on the same tracking — the customer breakout shows each individually.
    $inCostCenter = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2026-05-01', 'tracking_number' => 'TRK1', 'raw_charge_description' => 'Shipping Charge Correction', 'charge_category_id' => 10, 'amount' => 7.96, 'source_type' => 'pdf']);
    $noCostCenter = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2026-05-01', 'tracking_number' => 'TRK1', 'raw_charge_description' => 'Ground Commercial', 'charge_category_id' => 13, 'amount' => 7.24, 'source_type' => 'pdf']);

    Livewire::test(ListCarrierCharges::class)
        ->assertCanSeeTableRecords([$inCostCenter])
        ->assertCanNotSeeTableRecords([$noCostCenter])
        // Toggling it off shows every category.
        ->filterTable('cost_center_categories', false)
        ->assertCanSeeTableRecords([$inCostCenter, $noCostCenter]);
});
