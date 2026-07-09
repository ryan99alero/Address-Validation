<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Models\ImportBatch;
use App\Models\ShipViaCode;
use App\Models\TransitTime;
use App\Services\ShippingRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function groundBatchAddress(string $carrierSlug = 'fedex'): array
{
    $carrier = Carrier::factory()->create(['slug' => $carrierSlug, 'name' => 'FedEx', 'is_active' => true]);
    $batch = ImportBatch::factory()->create([
        'carrier_id' => $carrier->id, 'include_transit_times' => true, 'find_best_service' => true,
    ]);
    $address = Address::factory()->create([
        'import_batch_id' => $batch->id, 'required_on_site_date' => now()->addDays(5), 'validation_status' => 'valid',
    ]);
    TransitTime::factory()->create([
        'address_id' => $address->id, 'carrier_id' => $carrier->id,
        'service_type' => 'FEDEX_GROUND', 'service_name' => 'Ground', 'delivery_date' => now()->addDays(3),
    ]);

    return [$carrier, $batch, $address];
}

it('resolves the chosen service to the SELECTED plant ShipVia code', function () {
    ShipViaCode::create(['code' => 'G001', 'service_type' => 'FEDEX_GROUND', 'service_name' => 'Ground', 'plant_id' => 'PLANT001', 'is_active' => true]);
    ShipViaCode::create(['code' => 'G002', 'service_type' => 'FEDEX_GROUND', 'service_name' => 'Ground', 'plant_id' => 'PLANT002', 'is_active' => true]);

    [, , $address] = groundBatchAddress();

    app(ShippingRecommendationService::class)->applyBestWayOptimization($address, 'PLANT002');

    expect($address->fresh()->ship_via_code)->toBe('G002'); // PLANT002's code, not PLANT001's
});

it('falls back to the original row plant when no override is selected', function () {
    ShipViaCode::create(['code' => 'G001', 'service_type' => 'FEDEX_GROUND', 'service_name' => 'Ground', 'plant_id' => 'PLANT001', 'is_active' => true]);
    ShipViaCode::create(['code' => 'G002', 'service_type' => 'FEDEX_GROUND', 'service_name' => 'Ground', 'plant_id' => 'PLANT002', 'is_active' => true]);
    ShipViaCode::create(['code' => 'ORIG', 'service_type' => 'FEDEX_2_DAY', 'service_name' => '2Day', 'plant_id' => 'PLANT001', 'is_active' => true]);

    [, , $address] = groundBatchAddress();
    $address->update(['ship_via_code' => 'ORIG']); // original row is a PLANT001 code

    app(ShippingRecommendationService::class)->applyBestWayOptimization($address); // no override

    expect($address->fresh()->ship_via_code)->toBe('G001'); // PLANT001, from the row's original
});
