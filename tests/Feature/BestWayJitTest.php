<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Models\ShipViaCode;
use App\Models\TransitTime;
use App\Services\ShippingRecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00')); // Friday
    $this->carrier = Carrier::factory()->create(['slug' => 'fedex', 'is_active' => true]);
    $this->svc = new ShippingRecommendationService;
});

afterEach(fn () => Carbon::setTestNow());

function svcode(Carrier $c, string $code, string $type, string $account): void
{
    ShipViaCode::factory()->create([
        'carrier_id' => $c->id, 'code' => $code, 'service_type' => $type,
        'plant_id' => 'PLANT002', 'payment_type' => ShipViaCode::PAYMENT_SENDER,
        'account_number' => $account, 'is_active' => true,
    ]);
}

function jitAddress(Carrier $c): Address
{
    // Original: Ground on account AAA (our account).
    svcode($c, 'G1', 'FEDEX_GROUND', 'AAA');
    $a = Address::factory()->create([
        'requested_ship_date' => '2026-07-10',
        'required_on_site_date' => '2026-07-15',
        'ship_via_code' => 'G1',
        'validation_status' => 'valid',
    ]);
    foreach ([['FEDEX_GROUND', 'FOUR_DAYS'], ['FEDEX_EXPRESS_SAVER', 'THREE_DAYS'], ['FEDEX_2_DAY', 'TWO_DAYS'], ['STANDARD_OVERNIGHT', 'ONE_DAY']] as [$type, $days]) {
        TransitTime::factory()->create([
            'address_id' => $a->id, 'carrier_id' => $c->id, 'service_type' => $type,
            'service_name' => $type, 'maximum_transit_time' => $days, 'delivery_date' => now()->addDays(30),
        ]);
    }

    return $a->load('transitTimes');
}

it('upgrades late Ground to the on-time SAME-ACCOUNT overnight, arriving exactly on the in-store date', function () {
    $c = $this->carrier;
    // On-time cheap tiers exist but on a DIFFERENT account (client's) — must be ignored.
    svcode($c, 'ES', 'FEDEX_EXPRESS_SAVER', 'BBB');
    svcode($c, '2D', 'FEDEX_2_DAY', 'BBB');
    // Only the overnight is on our account AAA.
    svcode($c, 'OV', 'STANDARD_OVERNIGHT', 'AAA');

    $a = jitAddress($c);
    $this->svc->applyBestWayOptimization($a);
    $a->refresh();

    // Ground (7/9 ship) too late; Express Saver/2-Day are a different account → excluded.
    expect($a->ship_via_code)->toBe('OV')
        ->and($a->previous_ship_via_code)->toBe('G1')
        ->and($a->bestway_optimized)->toBeTrue()
        ->and($a->ship_via_meets_deadline)->toBeTrue()
        ->and((int) $a->ship_via_days)->toBe(1)
        ->and($a->recommended_ship_date->format('Y-m-d'))->toBe('2026-07-14') // 7/15 − 1 weekday
        ->and($a->ship_via_date->format('Y-m-d'))->toBe('2026-07-15');        // arrives exactly on in-store
});

it('does NOT jump to a cheaper different-account service even when one arrives on time', function () {
    $c = $this->carrier;
    svcode($c, 'ES', 'FEDEX_EXPRESS_SAVER', 'BBB'); // cheaper + on time, but different account
    svcode($c, 'OV', 'STANDARD_OVERNIGHT', 'AAA');

    $a = jitAddress($c);
    $this->svc->applyBestWayOptimization($a);

    expect($a->fresh()->ship_via_code)->toBe('OV'); // stayed on our account, not the cheaper ES
});

it('flags addresses where no same-account service can arrive in time (never silently ships late)', function () {
    $c = $this->carrier;
    // Only Ground (our account, too slow) and Express Saver (different account) exist.
    svcode($c, 'ES', 'FEDEX_EXPRESS_SAVER', 'BBB');

    $a = jitAddress($c);
    $this->svc->applyBestWayOptimization($a);
    $a->refresh();

    expect($a->bestway_optimized)->toBeFalse()
        ->and($a->ship_via_meets_deadline)->toBeFalse()
        ->and($a->ship_via_code)->toBe('G1'); // unchanged — flagged, not silently upgraded/late
});
