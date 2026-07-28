<?php

use App\Jobs\ReverifyCorrectedAddress;
use App\Models\Address;
use App\Models\AddressSupersession;
use App\Models\AddressVerification;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Services\AddressValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $extra
 */
function vGood(string $addr1, string $city, string $state, string $postal, array $extra = []): CorrectedAddress
{
    return CorrectedAddress::create(array_merge([
        'address_1' => $addr1, 'city' => $city, 'state' => $state, 'postal' => $postal, 'country' => 'us',
        'address_hash' => CorrectedAddress::computeHash($addr1, $city, $state, $postal, 'us'),
        'usage_count' => 1, 'variant_count' => 0, 'first_seen_at' => now(),
    ], $extra));
}

function mockValidation(callable $setOutput): AddressValidationService
{
    $mock = Mockery::mock(AddressValidationService::class);
    $mock->shouldReceive('useLocalCache')->andReturnSelf();
    $mock->shouldReceive('validateAddress')->andReturnUsing(function (Address $a) use ($setOutput): Address {
        $setOutput($a);

        return $a;
    });

    return $mock;
}

test('seed-verifications stamps a verified date per carrier from invoice evidence', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $good = vGood('100 main st', 'austin', 'tx', '78701');
    $invId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $carrier->id, 'invoice_number' => 'INV1', 'invoice_date' => '2020-06-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $invId, 'corrected_address_id' => $good->id, 'tracking_number' => 'T1',
        'original_address_1' => '100 main', 'original_postal' => '78701', 'original_country' => 'US',
        'ship_date' => '2020-05-20', 'charge_code' => 'ADC', 'charge_amount' => 11, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('correction-cache:seed-verifications')->assertSuccessful();

    $v = AddressVerification::where('corrected_address_id', $good->id)->where('carrier_id', $carrier->id)->first();
    expect($v)->not->toBeNull()
        ->and($v->status)->toBe('verified')
        ->and($v->source)->toBe('invoice')
        ->and($v->verified_at->toDateString())->toBe('2020-05-20');
});

test('reverify job stamps verified when the carrier returns the same address', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $good = vGood('100 main st', 'austin', 'tx', '78701');

    (new ReverifyCorrectedAddress($good->id, $carrier->id))->handle(mockValidation(function (Address $a): void {
        $a->output_address_1 = '100 MAIN ST';
        $a->output_city = 'AUSTIN';
        $a->output_state = 'TX';
        $a->output_postal = '78701';
    }));

    $v = AddressVerification::where('corrected_address_id', $good->id)->first();
    expect($v->status)->toBe('verified')
        ->and($v->source)->toBe('api')
        ->and($v->verified_at)->not->toBeNull();
});

test('reverify job marks drifted + queues a review event when the carrier wants a different address', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $good = vGood('100 main st', 'austin', 'tx', '78701');

    (new ReverifyCorrectedAddress($good->id, $carrier->id))->handle(mockValidation(function (Address $a): void {
        $a->output_address_1 = '200 OAK AVE';
        $a->output_city = 'AUSTIN';
        $a->output_state = 'TX';
        $a->output_postal = '78704';
    }));

    $v = AddressVerification::where('corrected_address_id', $good->id)->first();
    expect($v->status)->toBe('drifted')
        ->and($v->verified_at)->toBeNull()
        ->and($v->result_snapshot['postal'])->toBe('78704');
    expect(AddressSupersession::where('trigger', 'reverify_drift')->where('status', 'pending_review')->count())->toBe(1);
});

test('a reverify API failure records the attempt but never un-verifies a good address', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $good = vGood('100 main st', 'austin', 'tx', '78701');
    AddressVerification::create([
        'corrected_address_id' => $good->id, 'carrier_id' => $carrier->id,
        'status' => 'verified', 'verified_at' => now()->subMonths(2), 'checked_at' => now()->subMonths(2), 'source' => 'invoice',
    ]);

    (new ReverifyCorrectedAddress($good->id, $carrier->id))->handle(mockValidation(function (Address $a): void {
        $a->output_address_1 = null;
    }));

    $v = AddressVerification::where('corrected_address_id', $good->id)->first();
    expect($v->status)->toBe('verified')          // untouched
        ->and($v->verified_at)->not->toBeNull();  // NOT cleared by a transient failure
});

test('reverify command queues only stale / unverified addresses', function () {
    Queue::fake();
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS', 'is_active' => true]);

    vGood('1 stale st', 'austin', 'tx', '78701', ['usage_count' => 100]);
    $fresh = vGood('2 fresh st', 'austin', 'tx', '78702');
    AddressVerification::create([
        'corrected_address_id' => $fresh->id, 'carrier_id' => $carrier->id,
        'status' => 'verified', 'verified_at' => now()->subDays(10), 'checked_at' => now()->subDays(10), 'source' => 'invoice',
    ]);

    $this->artisan('correction-cache:reverify', ['--carrier' => 'ups', '--limit' => 10])->assertSuccessful();

    Queue::assertPushed(ReverifyCorrectedAddress::class, 1); // only the stale one
});
