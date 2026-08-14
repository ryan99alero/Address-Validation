<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stamps carton_cost_id era-correctly: recent charge links, old recycled charge stays null', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);

    // Same recycled 1Z on a 2013 (old) invoice and a recent invoice.
    $old = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'OLD1', 'invoice_date' => '2013-12-14', 'filename' => 'old.csv']);
    $recent = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'NEW1', 'invoice_date' => now()->toDateString(), 'filename' => 'new.csv']);

    $oldCharge = CarrierCharge::forceCreate(['carrier_invoice_id' => $old->id, 'carrier_id' => $carrier->id, 'invoice_date' => '2013-12-14', 'tracking_number' => 'TRK1', 'raw_charge_description' => 'Fuel', 'amount' => 1, 'source_type' => 'csv']);
    $recentCharge = CarrierCharge::forceCreate(['carrier_invoice_id' => $recent->id, 'carrier_id' => $carrier->id, 'invoice_date' => now()->toDateString(), 'tracking_number' => 'TRK1', 'raw_charge_description' => 'Fuel', 'amount' => 2, 'source_type' => 'pdf']);
    $noCartonCharge = CarrierCharge::forceCreate(['carrier_invoice_id' => $recent->id, 'carrier_id' => $carrier->id, 'invoice_date' => now()->toDateString(), 'tracking_number' => 'TRKX', 'raw_charge_description' => 'Fuel', 'amount' => 3, 'source_type' => 'pdf']);

    // The mirror holds one carton for the recycled tracking — the current era.
    $cartonId = DB::table('carton_costs')->insertGetId(['tracking_number' => 'TRK1', 'pace_job_number' => 'M254432', 'ship_date' => now()->toDateString(), 'ship_cost' => 5, 'created_at' => now(), 'updated_at' => now()]);

    $stamped = app(CartonCostSyncService::class)->stampRecentCharges();

    expect($stamped)->toBe(1)                                                     // only the recent charge on TRK1
        ->and((int) $recentCharge->fresh()->carton_cost_id)->toBe($cartonId)     // recent → linked
        ->and($oldCharge->fresh()->carton_cost_id)->toBeNull()                   // old recycled invoice → not stamped
        ->and($noCartonCharge->fresh()->carton_cost_id)->toBeNull();             // no carton for its tracking
});
