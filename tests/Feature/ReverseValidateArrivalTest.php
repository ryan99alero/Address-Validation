<?php

use App\Models\Address;
use App\Services\FedExServiceAvailabilityService;
use App\Services\ShippingRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function optimizedFutureAddress(): Address
{
    return Address::factory()->create([
        'validation_status' => 'valid',
        'bestway_optimized' => true,
        'bestway_service_type' => 'STANDARD_OVERNIGHT',
        'ship_via_meets_deadline' => true,
        'recommended_ship_date' => now()->addDays(14)->toDateString(),
        'required_on_site_date' => now()->addDays(20)->toDateString(),
        'ship_via_date' => now()->addDays(20)->toDateString(), // inferred = required (pre-check)
        'arrival_verified' => null,
    ]);
}

it('confirms an on-time future ship date and stores FedEx real delivery', function () {
    $address = optimizedFutureAddress();
    $realDelivery = now()->addDays(18); // <= required (day 20)

    $fedex = Mockery::mock(FedExServiceAvailabilityService::class);
    $fedex->shouldReceive('getDeliveryDateForShipDate')->once()->andReturn($realDelivery);

    $outcome = (new ShippingRecommendationService)->reverseValidateArrival($address, $fedex);

    expect($outcome)->toBe('confirmed')
        ->and($address->refresh()->arrival_verified)->toBeTrue()
        ->and($address->ship_via_date->toDateString())->toBe($realDelivery->toDateString())
        ->and($address->ship_via_meets_deadline)->toBeTrue();
});

it('flags a slipped arrival (holiday pushed it late) instead of hiding it', function () {
    $address = optimizedFutureAddress();
    $lateDelivery = now()->addDays(22); // > required (day 20)

    $fedex = Mockery::mock(FedExServiceAvailabilityService::class);
    $fedex->shouldReceive('getDeliveryDateForShipDate')->once()->andReturn($lateDelivery);

    $outcome = (new ShippingRecommendationService)->reverseValidateArrival($address, $fedex);

    expect($outcome)->toBe('slipped')
        ->and($address->refresh()->arrival_verified)->toBeFalse()
        ->and($address->ship_via_meets_deadline)->toBeFalse()
        ->and($address->ship_via_date->toDateString())->toBe($lateDelivery->toDateString());
});

it('skips a today-dated ship date without an API call', function () {
    $address = optimizedFutureAddress();
    $address->update(['recommended_ship_date' => now()->toDateString()]);

    $fedex = Mockery::mock(FedExServiceAvailabilityService::class);
    $fedex->shouldReceive('getDeliveryDateForShipDate')->never();

    expect((new ShippingRecommendationService)->reverseValidateArrival($address, $fedex))->toBe('skipped');
});

it('marks unverifiable when FedEx returns nothing', function () {
    $address = optimizedFutureAddress();

    $fedex = Mockery::mock(FedExServiceAvailabilityService::class);
    $fedex->shouldReceive('getDeliveryDateForShipDate')->once()->andReturnNull();

    $outcome = (new ShippingRecommendationService)->reverseValidateArrival($address, $fedex);

    expect($outcome)->toBe('unverifiable')
        ->and($address->refresh()->arrival_verified)->toBeNull();
});
