<?php

use App\Models\AccountOwner;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\CarrierAccount;
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

function svcode(Carrier $c, string $code, string $type, string $account, ?string $owner = null): void
{
    ShipViaCode::factory()->create([
        'carrier_id' => $c->id, 'code' => $code, 'service_type' => $type,
        'plant_id' => 'PLANT002', 'payment_type' => ShipViaCode::PAYMENT_SENDER,
        'account_number' => $account, 'account_owner' => $owner, 'is_active' => true,
    ]);
}

/** Address on Ground code G1 with a two-tier transit ladder (Ground 4-day + Overnight 1-day). */
function ownerJitAddress(Carrier $c): Address
{
    $a = Address::factory()->create([
        'requested_ship_date' => '2026-07-10',
        'required_on_site_date' => '2026-07-15',
        'ship_via_code' => 'G1',
        'validation_status' => 'valid',
    ]);
    foreach ([['FEDEX_GROUND', 'FOUR_DAYS'], ['STANDARD_OVERNIGHT', 'ONE_DAY']] as [$type, $days]) {
        TransitTime::factory()->create([
            'address_id' => $a->id, 'carrier_id' => $c->id, 'service_type' => $type,
            'service_name' => $type, 'maximum_transit_time' => $days, 'delivery_date' => now()->addDays(30),
        ]);
    }

    return $a->load('transitTimes');
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

it('computes transit duration from delivery_date when no max transit time is set (CarbonImmutable-safe)', function () {
    $c = $this->carrier;
    svcode($c, 'G1', 'FEDEX_GROUND', 'AAA');
    svcode($c, 'OV', 'STANDARD_OVERNIGHT', 'AAA');

    $a = Address::factory()->create([
        'requested_ship_date' => '2026-07-10',
        'required_on_site_date' => '2026-07-15',
        'ship_via_code' => 'G1',
        'validation_status' => 'valid',
    ]);
    // No maximum_transit_time — duration must come from delivery_date (the prod case
    // that hit the 365-iteration cap under CarbonImmutable).
    TransitTime::factory()->create([
        'address_id' => $a->id, 'carrier_id' => $c->id, 'service_type' => 'STANDARD_OVERNIGHT',
        'service_name' => 'FedEx Standard Overnight', 'maximum_transit_time' => null,
        'minimum_transit_time' => null, 'delivery_date' => '2026-07-13', 'calculated_at' => '2026-07-10',
    ]);
    TransitTime::factory()->create([
        'address_id' => $a->id, 'carrier_id' => $c->id, 'service_type' => 'FEDEX_GROUND',
        'service_name' => 'FedEx Ground', 'maximum_transit_time' => null,
        'minimum_transit_time' => null, 'delivery_date' => '2026-07-16', 'calculated_at' => '2026-07-10',
    ]);

    $this->svc->applyBestWayOptimization($a->load('transitTimes'));
    $a->refresh();

    // Overnight = 1 business day (7/10→7/13); Ground = 4 (7/9 ship, too early).
    expect($a->ship_via_code)->toBe('OV')
        ->and((int) $a->ship_via_days)->toBe(1)
        ->and($a->recommended_ship_date->format('Y-m-d'))->toBe('2026-07-14')
        ->and($a->bestway_optimized)->toBeTrue();
});

/** Ship-via code linked to a structured carrier account (Phase 3 pools by the account's owner). */
function acctCode(Carrier $c, string $code, string $type, CarrierAccount $account): void
{
    ShipViaCode::factory()->create([
        'carrier_id' => $c->id, 'code' => $code, 'service_type' => $type,
        'plant_id' => 'PLANT002', 'payment_type' => ShipViaCode::PAYMENT_SENDER,
        'carrier_account_id' => $account->id, 'account_number' => $account->account_number, 'is_active' => true,
    ]);
}

it('pools own accounts by owner: crosses to another OWN account for the on-time service', function () {
    $c = $this->carrier;
    // Plant002 reality: Ground and Overnight on SEPARATE accounts, both owned by RAND.
    $rand = AccountOwner::create(['name' => 'RAND', 'type' => AccountOwner::TYPE_COMPANY]);
    $ground = CarrierAccount::create(['carrier_id' => $c->id, 'account_number' => 'ACCT_GROUND', 'nickname' => 'Ground', 'account_owner_id' => $rand->id]);
    $priority = CarrierAccount::create(['carrier_id' => $c->id, 'account_number' => 'ACCT_PRIORITY', 'nickname' => 'Priority', 'account_owner_id' => $rand->id]);
    acctCode($c, 'G1', 'FEDEX_GROUND', $ground);
    acctCode($c, 'OV', 'STANDARD_OVERNIGHT', $priority);

    $this->svc->applyBestWayOptimization(ownerJitAddress($c));
    $a = Address::where('ship_via_code', 'OV')->first();

    // Ground (4-day) ships too early → crosses to the Priority account's Overnight. Same owner.
    expect($a)->not->toBeNull()
        ->and($a->bestway_optimized)->toBeTrue();
});

it('never crosses to a different owner — a client account stays off-limits', function () {
    $c = $this->carrier;
    $rand = AccountOwner::create(['name' => 'RAND', 'type' => AccountOwner::TYPE_COMPANY]);
    $acme = AccountOwner::create(['name' => 'Acme', 'type' => AccountOwner::TYPE_CUSTOMER]);
    $ground = CarrierAccount::create(['carrier_id' => $c->id, 'account_number' => 'ACCT_GROUND', 'nickname' => 'Ground', 'account_owner_id' => $rand->id]);
    $client = CarrierAccount::create(['carrier_id' => $c->id, 'account_number' => 'CLIENT_ACCT', 'nickname' => 'Client', 'account_owner_id' => $acme->id]);
    acctCode($c, 'G1', 'FEDEX_GROUND', $ground);
    acctCode($c, 'OVC', 'STANDARD_OVERNIGHT', $client); // only on-time overnight is the client's

    $a = ownerJitAddress($c);
    $this->svc->applyBestWayOptimization($a);
    $a->refresh();

    // No RAND-owned service arrives in time → flagged, not silently shipped on the client.
    expect($a->ship_via_code)->toBe('G1')
        ->and($a->bestway_optimized)->toBeFalse()
        ->and($a->ship_via_meets_deadline)->toBeFalse();
});

it('locks an owner-unassigned account to itself — never pools null owners', function () {
    $c = $this->carrier;
    // Two linked accounts with NO owner yet (the backfill worklist state). Must not pool.
    $a1 = CarrierAccount::create(['carrier_id' => $c->id, 'account_number' => 'A1', 'nickname' => 'A1']);
    $a2 = CarrierAccount::create(['carrier_id' => $c->id, 'account_number' => 'A2', 'nickname' => 'A2']);
    acctCode($c, 'G1', 'FEDEX_GROUND', $a1);
    acctCode($c, 'OV', 'STANDARD_OVERNIGHT', $a2);

    $a = ownerJitAddress($c);
    $this->svc->applyBestWayOptimization($a);
    $a->refresh();

    // G1's account has no owner → locks to A1; Overnight is on A2 → excluded → not optimized.
    expect($a->bestway_optimized)->toBeFalse();
});

it('anchors transit duration on the sent ship date, not the fetch date (holiday-aware)', function () {
    // Fetched 7/15 but quoted for a Fri 9/04 ship (before Labor Day Mon 9/07); FedEx now
    // honors the future date and returned a holiday-aware delivery of Tue 9/08. The
    // duration must be measured from the SHIP date, not the fetch date.
    $tt = new TransitTime([
        'delivery_date' => '2026-09-08',
        'ship_date' => '2026-09-04',
        'calculated_at' => '2026-07-15',
    ]);

    // Fri 9/4 → Tue 9/8 = 2 weekdays (Mon 9/7 + Tue 9/8), NOT the ~40 the fetch date gives.
    expect($tt->transitBusinessDays())->toBe(2);
});

it('falls back to calculated_at for legacy rows with no stored ship_date', function () {
    $tt = new TransitTime([
        'delivery_date' => '2026-07-13',
        'ship_date' => null,
        'calculated_at' => '2026-07-10', // Friday
    ]);

    expect($tt->transitBusinessDays())->toBe(1); // 7/10 Fri → 7/13 Mon = 1 weekday
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
