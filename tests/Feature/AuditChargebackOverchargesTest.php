<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\ChargebackPush;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flags a double-post duplicate among posted JobCosts', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $inv = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'filename' => 'x.csv']);
    $charge = CarrierCharge::forceCreate(['carrier_invoice_id' => $inv->id, 'carrier_id' => $carrier->id, 'invoice_date' => now()->toDateString(), 'tracking_number' => 'TRK1', 'raw_charge_description' => 'Shipping Charge Correction', 'amount' => 7.96, 'source_type' => 'pdf']);

    // The live-pushed row (current generation) + an orphaned duplicate from a prior generation.
    ChargebackPush::forceCreate(['carrier_charge_id' => $charge->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRK1', 'activity_code' => '72530', 'dedupe_key' => 'k1', 'amount' => 7.96, 'status' => 'pushed', 'pace_job' => 'M1', 'pace_jobcost_id' => '1001']);
    ChargebackPush::forceCreate(['carrier_charge_id' => null, 'carrier_id' => $carrier->id, 'tracking_number' => 'TRK1', 'activity_code' => '72530', 'dedupe_key' => 'k2', 'amount' => 6.85, 'status' => 'pushed', 'pace_job' => 'M1', 'pace_jobcost_id' => '1002']);

    $tmp = tempnam(sys_get_temp_dir(), 'audit').'.csv';
    $this->artisan('chargeback:audit-overcharges', ['--path' => $tmp])->assertSuccessful();

    $csv = file_get_contents($tmp);
    expect($csv)->toContain('DOUBLE_POST_DUP')  // the orphaned dup is flagged
        ->and($csv)->toContain('1002');          // its JobCost id is in the report
});
