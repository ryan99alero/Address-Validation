<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Models\TransitTime;
use App\Services\UpsTimeInTransitService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-22 09:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

// UPS Time-in-Transit returns a real businessTransitDays alongside totalTransitDays: 0. The parser
// used to store that 0 as maximum_transit_time '0_DAYS', which read back as a real zero and dropped
// getCalculatedTransitDays() through to the delivery_date loop — capping at 365. FedEx was unaffected
// because it always sends a real max. Shipping needs the true day count to stage by transit time.
test('a UPS transit row with totalTransitDays 0 reports the business-day count, not 365', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $address = Address::factory()->create();

    $t = TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $carrier->id,
        'service_type' => '3DS',
        'minimum_transit_time' => 'THREE_DAYS',
        'maximum_transit_time' => '0_DAYS',   // the poisoned value from totalTransitDays: 0
        'transit_days_description' => null,    // force the enum/number path
        'delivery_date' => now()->addYear(),   // far out — would yield 365 via the old loop
        'calculated_at' => now()->subYear(),
    ]);

    expect($t->getCalculatedTransitDays())->toBe('3')
        ->and($t->transit_range)->toBe('3 Days');
});

test('the UPS parser stores a clean max/description when totalTransitDays is 0', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $address = Address::factory()->create();
    $service = new UpsTimeInTransitService($carrier);

    // createTransitTimeFromService is protected; drive it directly with a real UPS service shape.
    $method = (new ReflectionClass($service))->getMethod('createTransitTimeFromService');
    $method->setAccessible(true);

    /** @var TransitTime $row */
    $row = $method->invoke($service, $address, [
        'serviceLevel' => '3DS',
        'serviceLevelDescription' => 'UPS 3 Day Select',
        'businessTransitDays' => 3,
        'totalTransitDays' => 0,
        'deliveryDate' => '2026-07-27',
    ], '90210', 'US');

    expect($row->minimum_transit_time)->toBe('THREE_DAYS')
        ->and($row->maximum_transit_time)->toBeNull()          // 0 no longer becomes '0_DAYS'
        ->and($row->transit_days_description)->toBe('3 Business Days')
        ->and($row->getCalculatedTransitDays())->toBe('3');
});
