<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Models\TransitTime;
use App\Services\ShippingRecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Monday 2026-07-13 is "today" so weekday math is deterministic.
    Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));
    $this->carrier = Carrier::factory()->create(['slug' => 'fedex', 'is_active' => true]);
    $this->service = new ShippingRecommendationService;
});

afterEach(function () {
    Carbon::setTestNow();
});

function transit(Address $address, Carrier $carrier, string $type, string $name, string $maxDays): void
{
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $carrier->id,
        'service_type' => $type,
        'service_name' => $name,
        'maximum_transit_time' => $maxDays,
        'delivery_date' => now()->addDays(30), // unused; max_transit drives the duration
    ]);
}

it('picks the cheapest service and the latest ship date that still arrives on time', function () {
    // Required Fri 2026-07-24, plenty of lead time. Ground (cheapest) should win.
    $address = Address::factory()->create([
        'required_on_site_date' => '2026-07-24',
        'validation_status' => 'valid',
    ]);
    transit($address, $this->carrier, 'FEDEX_GROUND', 'FedEx Ground', 'FIVE_DAYS');
    transit($address, $this->carrier, 'FEDEX_2_DAY', 'FedEx 2Day', 'TWO_DAYS');
    transit($address, $this->carrier, 'STANDARD_OVERNIGHT', 'FedEx Standard Overnight', 'ONE_DAY');

    $this->service->applyReverseSchedule($address->fresh()->load('transitTimes'));
    $address->refresh();

    // required 7/24 minus 5 weekdays = Fri 7/17
    expect($address->recommended_ship_service)->toContain('Ground')
        ->and($address->recommended_ship_date->format('Y-m-d'))->toBe('2026-07-17');
});

it('steps up to a faster service when the cheapest is too slow to make the deadline from today', function () {
    // Required Thu 2026-07-16. Ground (5d) would need shipping 7/9 (past) → not viable.
    $address = Address::factory()->create([
        'required_on_site_date' => '2026-07-16',
        'validation_status' => 'valid',
    ]);
    transit($address, $this->carrier, 'FEDEX_GROUND', 'FedEx Ground', 'FIVE_DAYS');
    transit($address, $this->carrier, 'FEDEX_2_DAY', 'FedEx 2Day', 'TWO_DAYS');
    transit($address, $this->carrier, 'STANDARD_OVERNIGHT', 'FedEx Standard Overnight', 'ONE_DAY');

    $this->service->applyReverseSchedule($address->fresh()->load('transitTimes'));
    $address->refresh();

    // Cheapest viable = 2Day; 7/16 minus 2 weekdays = Tue 7/14
    expect($address->recommended_ship_service)->toContain('2Day')
        ->and($address->recommended_ship_date->format('Y-m-d'))->toBe('2026-07-14');
});

it('records no ship date when even the fastest service cannot arrive in time', function () {
    // Required today (Mon 7/13) — even overnight would need shipping last Friday.
    $address = Address::factory()->create([
        'required_on_site_date' => '2026-07-13',
        'validation_status' => 'valid',
    ]);
    transit($address, $this->carrier, 'STANDARD_OVERNIGHT', 'FedEx Standard Overnight', 'ONE_DAY');

    $this->service->applyReverseSchedule($address->fresh()->load('transitTimes'));
    $address->refresh();

    expect($address->recommended_ship_date)->toBeNull()
        ->and($address->recommended_ship_service)->toBeNull();
});

it('does nothing without a required on-site date', function () {
    $address = Address::factory()->create([
        'required_on_site_date' => null,
        'validation_status' => 'valid',
    ]);
    transit($address, $this->carrier, 'FEDEX_GROUND', 'FedEx Ground', 'FIVE_DAYS');

    $changed = $this->service->applyReverseSchedule($address->fresh()->load('transitTimes'));

    expect($changed)->toBeFalse()
        ->and($address->fresh()->recommended_ship_date)->toBeNull();
});

it('bulk reverse-schedules and reports counts', function () {
    $onTime = Address::factory()->create(['required_on_site_date' => '2026-07-24', 'validation_status' => 'valid']);
    transit($onTime, $this->carrier, 'FEDEX_GROUND', 'FedEx Ground', 'FIVE_DAYS');

    $tooLate = Address::factory()->create(['required_on_site_date' => '2026-07-13', 'validation_status' => 'valid']);
    transit($tooLate, $this->carrier, 'STANDARD_OVERNIGHT', 'FedEx Standard Overnight', 'ONE_DAY');

    $addresses = Address::whereIn('id', [$onTime->id, $tooLate->id])->with('transitTimes')->get();
    $result = $this->service->applyReverseScheduleBatch($addresses);

    expect($result['processed'])->toBe(2)
        ->and($result['scheduled'])->toBe(1)
        ->and($result['cannot_meet'])->toBe(1)
        ->and($onTime->fresh()->recommended_ship_date)->not->toBeNull()
        ->and($tooLate->fresh()->recommended_ship_date)->toBeNull();
});
