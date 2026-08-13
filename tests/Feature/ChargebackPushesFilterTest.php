<?php

use App\Filament\Pages\ChargebackPushes;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\ChargebackPush;
use App\Models\ChargeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Two pushed ledger rows sharing every field except the one under test; p2 is a near-match so exact
 * Job/Customer filters must exclude it.
 *
 * @return array{0: ChargebackPush, 1: ChargebackPush}
 */
function chargebackFilterFixture(): array
{
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    ChargeCategory::forceCreate(['id' => 13, 'name' => 'Base Transportation']);
    ChargeCategory::forceCreate(['id' => 2, 'name' => 'Fuel Surcharge']);
    $inv1 = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-100', 'invoice_date' => '2026-05-01', 'filename' => 'a.csv']);
    $inv2 = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-200', 'invoice_date' => '2026-05-01', 'filename' => 'b.csv']);

    $p1 = ChargebackPush::forceCreate(['carrier_id' => $carrier->id, 'carrier_invoice_id' => $inv1->id, 'tracking_number' => 'TRK1', 'pace_job' => 'M254432', 'pace_customer_id' => 'C1', 'activity_code' => '72530', 'driver' => 'audit_correction', 'charge_category_id' => 13, 'dedupe_key' => 'k1', 'amount' => 5, 'status' => ChargebackPush::STATUS_PUSHED]);
    $p2 = ChargebackPush::forceCreate(['carrier_id' => $carrier->id, 'carrier_invoice_id' => $inv2->id, 'tracking_number' => 'TRK2', 'pace_job' => 'M254432X', 'pace_customer_id' => 'C1X', 'activity_code' => '72520', 'driver' => 'audit_correction', 'charge_category_id' => 2, 'dedupe_key' => 'k2', 'amount' => 3, 'status' => ChargebackPush::STATUS_PUSHED]);

    DB::table('carton_costs')->insert([
        ['tracking_number' => 'TRK1', 'pace_job_number' => 'M254432', 'pace_customer_id' => 'C1', 'U_reference' => 'PO-1', 'U_reference2' => 'R2A', 'ship_cost' => 8, 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TRK2', 'pace_job_number' => 'M254432X', 'pace_customer_id' => 'C1X', 'U_reference' => 'PO-2', 'U_reference2' => 'R2B', 'ship_cost' => 8, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('carrier_invoice_lines')->insert(['carrier_invoice_id' => $inv1->id, 'tracking_number' => 'TRK1', 'original_address_1' => '100 Rodeo Dr', 'original_city' => 'Beverly Hills', 'original_state' => 'CA', 'original_postal' => '90210', 'original_country' => 'US', 'ship_date' => '2026-05-01', 'charge_code' => 'ADC', 'charge_amount' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_shipments')->insert([
        ['carrier_invoice_id' => $inv1->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRK1', 'ship_date' => '2026-05-01', 'zip' => '90210', 'service' => 'Ground', 'source_type' => 'derived', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_invoice_id' => $inv2->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRK2', 'ship_date' => '2026-05-01', 'zip' => '10001', 'service' => 'Express', 'source_type' => 'derived', 'created_at' => now(), 'updated_at' => now()],
    ]);

    return [$p1, $p2];
}

it('filters the chargeback ledger by every shared field', function () {
    $this->actingAs(User::factory()->create());
    [$p1, $p2] = chargebackFilterFixture();

    foreach ([
        ['job', 'M254432'],            // pace_job exact
        ['customer', 'C1'],            // pace_customer_id exact
        ['tracking', 'TRK1'],          // direct column
        ['invoice_number', 'INV-100'], // whereHas invoice
        ['activity', '72530'],         // direct column
        ['reference1', 'PO-1'],        // carton U_reference
        ['reference2', 'R2A'],         // carton U_reference2
        ['address', 'Rodeo'],          // invoice line
        ['city', 'Beverly'],           // invoice line
        ['state', 'CA'],               // invoice line
        ['zip', '90210'],              // carrier_shipments by tracking
        ['service', 'Ground'],         // carrier_shipments by tracking
    ] as [$filter, $value]) {
        Livewire::test(ChargebackPushes::class)
            ->filterTable($filter, ['value' => $value])
            ->assertCanSeeTableRecords([$p1])
            ->assertCanNotSeeTableRecords([$p2]);
    }
});

it('filters the ledger by carrier, category and driver selects', function () {
    $this->actingAs(User::factory()->create());
    [$p1, $p2] = chargebackFilterFixture();

    Livewire::test(ChargebackPushes::class)
        ->filterTable('charge_category_id', $p1->charge_category_id)
        ->assertCanSeeTableRecords([$p1])
        ->assertCanNotSeeTableRecords([$p2]);
});
