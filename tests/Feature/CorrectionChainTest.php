<?php

use App\Models\AddressSupersession;
use App\Models\AddressVariant;
use App\Models\AddressVerification;
use App\Models\Carrier;
use App\Models\CarrierInvoiceLine;
use App\Models\CartonCost;
use App\Models\CorrectedAddress;
use App\Models\ZipCentroid;
use App\Services\Invoices\CorrectionGuard;
use App\Services\Invoices\CorrectionThreader;
use Illuminate\Support\Facades\DB;

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

test('applyPending supersedes and flips the review event to applied', function () {
    $a = chainGood('1 old rd', 'austin', 'tx', '78701');
    $b = chainGood('1 new rd', 'austin', 'tx', '78702');
    $bad = chainVariant($a->id, '9 bad st', 'austin', 'tx', '78701', 3);
    $a->update(['variant_count' => 1]);
    $event = app(CorrectionThreader::class)->recordEvent($a, $b, AddressSupersession::TRIGGER_MANUAL, AddressSupersession::STATUS_PENDING_REVIEW);

    expect(app(CorrectionThreader::class)->applyPending($event, null))->toBeTrue()
        ->and($event->fresh()->status)->toBe('applied')
        ->and($a->fresh()->superseded_by_id)->toBe($b->id)
        ->and($bad->fresh()->corrected_address_id)->toBe($b->id);
});

test('applyPending honors a manual corrected_override — supersedes to the edited address', function () {
    $a = chainGood('1 shipped rd', 'austin', 'tx', '78701');
    $b = chainGood('1 carrier rd', 'austin', 'tx', '78702'); // what the carrier said
    $bad = chainVariant($a->id, '9 bad st', 'austin', 'tx', '78701', 3);
    $a->update(['variant_count' => 1]);

    $event = app(CorrectionThreader::class)->recordEvent($a, $b, AddressSupersession::TRIGGER_MANUAL, AddressSupersession::STATUS_PENDING_REVIEW);
    // Human overrides the corrected address to a DIFFERENT (edited) form.
    $event->update([
        'corrected_override' => ['address_1' => '500 human st', 'address_2' => 'ste 9', 'city' => 'austin', 'state' => 'tx', 'postal' => '78703'],
        'corrected_edited_at' => now(),
    ]);

    expect(app(CorrectionThreader::class)->applyPending($event->fresh(), null))->toBeTrue();

    $edited = CorrectedAddress::where('address_hash', CorrectedAddress::computeHash('500 human st', 'austin', 'tx', '78703', 'us'))->first();
    expect($edited)->not->toBeNull()
        ->and($a->fresh()->superseded_by_id)->toBe($edited->id)   // superseded to the EDITED address, not the carrier's
        ->and($b->fresh()->superseded_by_id)->toBeNull()          // carrier's version untouched
        ->and($bad->fresh()->corrected_address_id)->toBe($edited->id);
});

test('applyPending is a no-op on a non-pending event', function () {
    $a = chainGood('2 old rd', 'austin', 'tx', '78701');
    $b = chainGood('2 new rd', 'austin', 'tx', '78702');
    $event = app(CorrectionThreader::class)->recordEvent($a, $b, AddressSupersession::TRIGGER_BACKFILL, AddressSupersession::STATUS_REJECTED_GARBAGE);

    expect(app(CorrectionThreader::class)->applyPending($event, null))->toBeFalse()
        ->and($a->fresh()->superseded_by_id)->toBeNull();
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

test('rebuildSearchText indexes tracking, job, invoice and both addresses for the search box', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $invId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $carrier->id, 'invoice_number' => 'INVX9', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $b = chainGood('100 main st', 'austin', 'tx', '78701');
    $c = chainGood('100 main st', 'austin', 'tx', '78702');

    // Correction 1: something -> B
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $invId, 'corrected_address_id' => $b->id, 'tracking_number' => 'TRKONE',
        'original_address_1' => '99 old rd', 'original_city' => 'austin', 'original_state' => 'tx', 'original_postal' => '78701', 'original_country' => 'US',
        'ship_date' => '2020-01-01', 'charge_code' => 'ADC', 'charge_amount' => 11, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Correction 2: B -> C  (original is B)
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $invId, 'corrected_address_id' => $c->id, 'tracking_number' => 'TRKTWO',
        'original_address_1' => '100 Main St', 'original_city' => 'Austin', 'original_state' => 'TX', 'original_postal' => '78701', 'original_country' => 'US',
        'ship_date' => '2026-01-01', 'charge_code' => 'ADC', 'charge_amount' => 12, 'created_at' => now(), 'updated_at' => now(),
    ]);
    CartonCost::create(['tracking_number' => 'TRKTWO', 'pace_job_number' => 'JOB777', 'pace_customer_name' => 'Acme Co']);

    $ev = app(CorrectionThreader::class)->recordEvent($b, $c, AddressSupersession::TRIGGER_BACKFILL, AddressSupersession::STATUS_PENDING_REVIEW);

    $fresh = $ev->fresh();
    expect($fresh->search_text)->toContain('trkone')       // correction 1 tracking
        ->toContain('trktwo')               // correction 2 tracking
        ->toContain('job777')               // Pace job
        ->toContain('acme co')              // Pace customer
        ->toContain('invx9')                // invoice number
        ->toContain('100 main st');         // address
    // reference_date is the correction-2 ship date, not the processing timestamp.
    expect($fresh->reference_date->toDateString())->toBe('2026-01-01');
    // The Re-Corrections columns get the tracking + Pace job/customer of the correction that made B->C.
    expect($fresh->tracking)->toBe('TRKTWO')
        ->and($fresh->pace_job)->toBe('JOB777')
        ->and($fresh->pace_customer_name)->toBe('Acme Co');
});

// --- Phase 3: ingest-time threading -----------------------------------------

function ingestInvoiceId(): int
{
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);

    return DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $carrier->id, 'invoice_number' => 'INV'.$carrier->id, 'invoice_date' => '2026-05-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @param  array{0: string, 1: string, 2: string, 3: string}  $orig
 * @param  array{0: string, 1: string, 2: string, 3: string}  $corr
 */
function ingestCorrection(int $invId, array $orig, array $corr): CarrierInvoiceLine
{
    $line = CarrierInvoiceLine::create([
        'carrier_invoice_id' => $invId,
        'tracking_number' => 'TRK-'.$orig[3].'-'.$corr[3],
        'original_address_1' => $orig[0], 'original_city' => $orig[1], 'original_state' => $orig[2], 'original_postal' => $orig[3], 'original_country' => 'US',
        'corrected_address_1' => $corr[0], 'corrected_city' => $corr[1], 'corrected_state' => $corr[2], 'corrected_postal' => $corr[3], 'corrected_country' => 'US',
        'charge_code' => 'ADC', 'charge_amount' => 11, 'ship_date' => '2026-05-01',
    ]);
    $line->linkToCorrectionCache();

    return $line;
}

function findGood(string $a, string $c, string $s, string $p): ?CorrectedAddress
{
    return CorrectedAddress::where('address_hash', CorrectedAddress::computeHash($a, $c, $s, $p, 'us'))->first();
}

test('ingest T2: a conflicting correction threads the old good into the new (Culver drift caught live)', function () {
    $invId = ingestInvoiceId();
    $g = chainGood('14431 culver dr', 'irvine', 'ca', '92614');
    chainVariant($g->id, '14431 culver dr', 'irvine', 'ca', '92714', 5);

    ingestCorrection($invId, ['14431 culver dr', 'irvine', 'ca', '92714'], ['14431 culver dr', 'irvine', 'ca', '92604']);

    $c = findGood('14431 culver dr', 'irvine', 'ca', '92604');
    expect($c)->not->toBeNull()
        ->and($g->fresh()->superseded_by_id)->toBe($c->id);
});

test('ingest T1: re-correction of a held-good address threads it and re-points its variants', function () {
    $invId = ingestInvoiceId();
    $a = chainGood('100 main st', 'austin', 'tx', '78701');
    $bad = chainVariant($a->id, '55 bad ave', 'austin', 'tx', '78701', 3);

    ingestCorrection($invId, ['100 main st', 'austin', 'tx', '78701'], ['100 main st', 'austin', 'tx', '78702']);

    $c = findGood('100 main st', 'austin', 'tx', '78702');
    expect($a->fresh()->superseded_by_id)->toBe($c->id)
        ->and($bad->fresh()->corrected_address_id)->toBe($c->id);
});

test('ingest garbage: a far different-state correction is refused, not taught to the cache', function () {
    $invId = ingestInvoiceId();
    ZipCentroid::create(['zip' => '77042', 'lat' => 29.74, 'lng' => -95.55]);
    ZipCentroid::create(['zip' => '72634', 'lat' => 36.23, 'lng' => -92.68]);

    ingestCorrection($invId, ['10 main st', 'houston', 'tx', '77042'], ['10 main st', 'x', 'ar', '72634']);

    $badHash = AddressVariant::computeHash('10 main st', 'houston', 'tx', '77042', 'us');
    expect(AddressVariant::where('input_hash', $badHash)->count())->toBe(0)
        ->and(AddressSupersession::where('status', 'rejected_garbage')->count())->toBe(1);
});

test('ingest kill-switch off falls back to the old file-and-forget behavior', function () {
    config(['correction_cache.ingest_threading' => false]);
    $invId = ingestInvoiceId();
    $g = chainGood('14431 culver dr', 'irvine', 'ca', '92614');
    chainVariant($g->id, '14431 culver dr', 'irvine', 'ca', '92714', 5);

    ingestCorrection($invId, ['14431 culver dr', 'irvine', 'ca', '92714'], ['14431 culver dr', 'irvine', 'ca', '92604']);

    expect($g->fresh()->superseded_by_id)->toBeNull();
});
