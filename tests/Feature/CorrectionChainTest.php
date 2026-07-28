<?php

use App\Models\AddressSupersession;
use App\Models\AddressVariant;
use App\Models\AddressVerification;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Models\ZipCentroid;
use App\Services\Invoices\CorrectionGuard;
use App\Services\Invoices\CorrectionThreader;

function chainGood(string $addr1, string $city, string $state, string $postal): CorrectedAddress
{
    return CorrectedAddress::create([
        'address_1' => $addr1, 'city' => $city, 'state' => $state, 'postal' => $postal, 'country' => 'us',
        'address_hash' => CorrectedAddress::computeHash($addr1, $city, $state, $postal, 'us'),
        'usage_count' => 1, 'variant_count' => 0, 'first_seen_at' => now(),
    ]);
}

function chainVariant(int $correctedId, string $addr1, string $city, string $state, string $postal, int $seen = 1): AddressVariant
{
    return AddressVariant::create([
        'corrected_address_id' => $correctedId,
        'input_address_1' => $addr1, 'input_city' => $city, 'input_state' => $state, 'input_postal' => $postal,
        'input_hash' => AddressVariant::computeHash($addr1, $city, $state, $postal, 'us'),
        'is_active' => true, 'times_seen' => $seen, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

// --- CorrectionGuard --------------------------------------------------------

test('guard APPLIES a local same-street ZIP-only correction (the Irvine case)', function () {
    $r = (new CorrectionGuard)->evaluate(
        ['address_1' => '14431 culver dr', 'city' => 'irvine', 'state' => 'ca', 'postal' => '92714'],
        ['address_1' => '14431 culver dr', 'city' => 'irvine', 'state' => 'ca', 'postal' => '92604'],
    );
    expect($r['verdict'])->toBe(CorrectionGuard::APPLY);
});

test('guard sends a same-region state change to REVIEW', function () {
    $r = (new CorrectionGuard)->evaluate(
        ['address_1' => '100 main st', 'city' => 'x', 'state' => 'ks', 'postal' => '66101'],
        ['address_1' => '100 main st', 'city' => 'y', 'state' => 'mo', 'postal' => '64101'],
    );
    expect($r['verdict'])->toBe(CorrectionGuard::REVIEW)->and($r['reason'])->toBe('state_changed');
});

test('guard REJECTS a far-away different-state correction as garbage', function () {
    ZipCentroid::create(['zip' => '77042', 'lat' => 29.74, 'lng' => -95.55]); // Houston TX
    ZipCentroid::create(['zip' => '72634', 'lat' => 36.23, 'lng' => -92.68]); // Arkansas
    $r = (new CorrectionGuard)->evaluate(
        ['address_1' => '10665 richmond ave', 'city' => 'houston', 'state' => 'tx', 'postal' => '77042'],
        ['address_1' => '10665 richmond ave', 'city' => 'x', 'state' => 'ar', 'postal' => '72634'],
    );
    expect($r['verdict'])->toBe(CorrectionGuard::REJECT)->and($r['reason'])->toBe('garbage_far_state');
});

test('guard REJECTS a degenerate target', function () {
    $r = (new CorrectionGuard)->evaluate(
        ['address_1' => '100 main st', 'state' => 'ca', 'postal' => '90001'],
        ['address_1' => '', 'state' => 'ca', 'postal' => '900'],
    );
    expect($r['verdict'])->toBe(CorrectionGuard::REJECT)->and($r['reason'])->toBe('degenerate_target');
});

test('guard sends a same-state different-building correction to REVIEW', function () {
    $r = (new CorrectionGuard)->evaluate(
        ['address_1' => '100 main st', 'city' => 'austin', 'state' => 'tx', 'postal' => '78701'],
        ['address_1' => '900 congress ave', 'city' => 'austin', 'state' => 'tx', 'postal' => '78701'],
    );
    expect($r['verdict'])->toBe(CorrectionGuard::REVIEW)->and($r['reason'])->toBe('street_core_changed');
});

// --- CorrectionThreader -----------------------------------------------------

test('threader supersedes, re-points variants, logs an applied event and stamps verification', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $a = chainGood('100 old st', 'austin', 'tx', '78701');
    $c = chainGood('100 new st', 'austin', 'tx', '78702');
    $bad = chainVariant($a->id, '55 bad ave', 'austin', 'tx', '78701', 9);
    $a->update(['variant_count' => 1]);

    $event = app(CorrectionThreader::class)->thread($a, $c, [
        'trigger' => AddressSupersession::TRIGGER_RECORRECTION,
        'carrier_id' => $carrier->id, 'date' => '2026-03-01',
    ]);

    expect($event)->not->toBeNull()
        ->and($event->status)->toBe('applied')
        ->and($a->fresh()->superseded_by_id)->toBe($c->id)
        ->and($bad->fresh()->corrected_address_id)->toBe($c->id)   // variant re-pointed to terminal
        ->and($a->fresh()->variant_count)->toBe(0)
        ->and($c->fresh()->variant_count)->toBe(1)
        ->and($a->fresh()->resolveTerminal()->id)->toBe($c->id);

    expect(AddressVerification::where('corrected_address_id', $a->id)->first()->status)->toBe('drifted')
        ->and(AddressVerification::where('corrected_address_id', $c->id)->first()->status)->toBe('verified');
});

test('threader breaks a reversal instead of forming a cycle', function () {
    $a = chainGood('1 a st', 'austin', 'tx', '78701');
    $b = chainGood('2 b st', 'austin', 'tx', '78702');
    app(CorrectionThreader::class)->thread($a, $b, ['trigger' => AddressSupersession::TRIGGER_MANUAL]);
    // now carrier re-crowns A: thread B -> A. Must not create A->B->A.
    app(CorrectionThreader::class)->thread($b, $a, ['trigger' => AddressSupersession::TRIGGER_MANUAL]);

    expect($b->fresh()->superseded_by_id)->toBe($a->id)
        ->and($a->fresh()->superseded_by_id)->toBeNull()          // A is terminal again
        ->and($a->fresh()->resolveTerminal()->id)->toBe($a->id);
});

// --- Backfill ---------------------------------------------------------------

test('backfill threads a re-corrected address (APPLY) and marks it superseded', function () {
    $a = chainGood('319 s 5th st', 'salina', 'ks', '67401');
    $g = chainGood('319 s 5th st', 'salina', 'ks', '67402'); // same street/state, ZIP-only drift, no centroids -> APPLY
    chainVariant($g->id, '319 s 5th st', 'salina', 'ks', '67401', 12); // signature matches A (postal+hash)

    $this->artisan('correction-cache:backfill-chains')->assertSuccessful();

    expect($a->fresh()->superseded_by_id)->toBe($g->id)
        ->and(AddressSupersession::where('status', 'applied')->count())->toBe(1);
});

test('backfill deactivates the poisoning variant for a garbage correction and does NOT supersede', function () {
    ZipCentroid::create(['zip' => '77042', 'lat' => 29.74, 'lng' => -95.55]);
    ZipCentroid::create(['zip' => '72634', 'lat' => 36.23, 'lng' => -92.68]);
    $a = chainGood('10665 richmond ave', 'houston', 'tx', '77042');
    $g = chainGood('10665 richmond ave', 'x', 'ar', '72634');
    $variant = chainVariant($g->id, '10665 richmond ave', 'houston', 'tx', '77042', 5);

    $this->artisan('correction-cache:backfill-chains')->assertSuccessful();

    expect($variant->fresh()->is_active)->toBeFalse()
        ->and($a->fresh()->superseded_by_id)->toBeNull()
        ->and(AddressSupersession::where('status', 'rejected_garbage')->count())->toBe(1);
});

test('backfill --dry-run changes nothing', function () {
    $a = chainGood('319 s 5th st', 'salina', 'ks', '67401');
    $g = chainGood('319 s 5th st', 'salina', 'ks', '67402');
    chainVariant($g->id, '319 s 5th st', 'salina', 'ks', '67401', 12);

    $this->artisan('correction-cache:backfill-chains', ['--dry-run' => true])->assertSuccessful();

    expect($a->fresh()->superseded_by_id)->toBeNull()
        ->and(AddressSupersession::count())->toBe(0);
});
