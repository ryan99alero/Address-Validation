<?php

use App\Jobs\ProcessPaceAddressCorrection;
use App\Models\Carrier;
use App\Models\ShipViaCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function swapResolver(): ReflectionMethod
{
    $m = new ReflectionMethod(ProcessPaceAddressCorrection::class, 'resolveHomeDeliverySwap');
    $m->setAccessible(true);

    return $m;
}

it('swaps a residential FedEx Ground ship-via to Home Delivery on the same plant + account', function () {
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    ShipViaCode::create(['code' => '5137', 'carrier_code' => 'FDG', 'carrier_id' => $fedex->id, 'service_type' => 'FEDEX_GROUND', 'service_name' => 'FEDEX GROUND', 'plant_id' => 'PLANT001', 'account_number' => 'ACCT1', 'is_active' => true]);
    $home = ShipViaCode::create(['code' => '5138', 'carrier_code' => 'FHD', 'carrier_id' => $fedex->id, 'service_type' => 'GROUND_HOME_DELIVERY', 'service_name' => 'GROUND HOME DELIVERY', 'plant_id' => 'PLANT001', 'account_number' => 'ACCT1', 'is_active' => true]);

    $result = swapResolver()->invoke(new ProcessPaceAddressCorrection(1, []), '5137');

    expect($result)->not->toBeNull()
        ->and($result->code)->toBe('5138')
        ->and($result->id)->toBe($home->id);
});

it('does not swap when there is no Home Delivery for the same plant/account', function () {
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    ShipViaCode::create(['code' => '5137', 'carrier_code' => 'FDG', 'carrier_id' => $fedex->id, 'service_type' => 'FEDEX_GROUND', 'service_name' => 'FEDEX GROUND', 'plant_id' => 'PLANT004', 'account_number' => 'ACCT9', 'is_active' => true]);
    // A Home Delivery exists, but on a different plant/account — must NOT be used.
    ShipViaCode::create(['code' => '5138', 'carrier_code' => 'FHD', 'carrier_id' => $fedex->id, 'service_type' => 'GROUND_HOME_DELIVERY', 'service_name' => 'GROUND HOME DELIVERY', 'plant_id' => 'PLANT001', 'account_number' => 'ACCT1', 'is_active' => true]);

    expect(swapResolver()->invoke(new ProcessPaceAddressCorrection(1, []), '5137'))->toBeNull();
});

it('does not swap UPS Ground or an unknown ship-via', function () {
    $ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    ShipViaCode::create(['code' => '5090', 'carrier_code' => 'GND', 'carrier_id' => $ups->id, 'service_type' => 'GND', 'service_name' => 'UPS Ground', 'plant_id' => 'PLANT001', 'account_number' => 'ACCT2', 'is_active' => true]);

    $job = new ProcessPaceAddressCorrection(1, []);
    expect(swapResolver()->invoke($job, '5090'))->toBeNull()
        ->and(swapResolver()->invoke($job, '9999'))->toBeNull();
});
