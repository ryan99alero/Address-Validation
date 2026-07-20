<?php

use App\Models\AddressVariant;
use App\Models\CorrectedAddress;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A Pace correction log as written by the real-time cleanup: FedEx-API sourced, original->corrected. */
function paceLog(array $original, array $corrected, string $source = 'fedex_api'): SystemLog
{
    return SystemLog::create([
        'category' => 'integration',
        'type' => 'pace_address_correction',
        'level' => 'info',
        'summary' => 'Pace address correction',
        'metadata' => [
            'source' => $source,
            'job_number' => 'M1',
            'original' => $original,
            'corrected' => $corrected,
            'changed_fields' => ['zip'],
        ],
    ]);
}

/** Register an original->corrected mapping into the local cache the way invoice import does. */
function seedCache(array $original, array $corrected): void
{
    $ca = CorrectedAddress::findOrCreateFromCorrection(
        $corrected['address1'], $corrected['address2'] ?? null, null,
        $corrected['city'], $corrected['state'], $corrected['zip'], null, $corrected['country'] ?? 'us'
    )['address'];

    AddressVariant::createOrUpdateVariant(
        $ca->id, $original['address1'], $original['address2'] ?? null,
        $original['city'], $original['state'], $original['zip'], $original['country'] ?? 'us'
    );
}

$orig = ['address1' => '2211 VIEGA AVE', 'address2' => '', 'city' => 'MCPHERSON', 'state' => 'KS', 'zip' => '67460', 'country' => 'US'];
$corr = ['address1' => '2211 VIEGA AVE', 'address2' => '', 'city' => 'MCPHERSON', 'state' => 'KS', 'zip' => '67460-8139', 'country' => 'US'];

test('a fedex_api correction now covered by the cache is re-tagged to local_cache', function () use ($orig, $corr) {
    seedCache($orig, $corr);
    $log = paceLog($orig, $corr);

    $this->artisan('pace:recheck-cache')->assertSuccessful();

    $log->refresh();
    expect($log->metadata['source'])->toBe('local_cache')
        ->and($log->metadata['previous_source'])->toBe('fedex_api')
        ->and($log->metadata['recheck_result'])->toBe('cache_hit')
        ->and($log->metadata['rechecked_at'])->not->toBeNull();
});

test('a correction not in the cache stays fedex_api but is stamped rechecked', function () use ($orig, $corr) {
    $log = paceLog($orig, $corr); // no seedCache

    $this->artisan('pace:recheck-cache')->assertSuccessful();

    $log->refresh();
    expect($log->metadata['source'])->toBe('fedex_api')
        ->and($log->metadata['recheck_result'])->toBe('miss')
        ->and($log->metadata['rechecked_at'])->not->toBeNull();
});

test('original in cache but a DIFFERENT correction is flagged, not flipped', function () use ($orig, $corr) {
    // Cache maps the same original to a materially different corrected STREET (postal alone reduces to
    // ZIP5, so a genuine difference must be on street/city/state to be distinguishable).
    seedCache($orig, ['address1' => '999 SOMEWHERE ELSE RD', 'address2' => '', 'city' => 'MCPHERSON', 'state' => 'KS', 'zip' => '67460-8139', 'country' => 'US']);
    $log = paceLog($orig, $corr);

    $this->artisan('pace:recheck-cache')->assertSuccessful();

    expect($log->refresh()->metadata['source'])->toBe('fedex_api')
        ->and($log->metadata['recheck_result'])->toBe('cache_hit_diff');
});

test('dry-run writes nothing', function () use ($orig, $corr) {
    seedCache($orig, $corr);
    $log = paceLog($orig, $corr);

    $this->artisan('pace:recheck-cache', ['--dry-run' => true])->assertSuccessful();

    expect($log->refresh()->metadata['source'])->toBe('fedex_api')
        ->and($log->metadata)->not->toHaveKey('rechecked_at');
});

test('already-rechecked rows are skipped, and local_cache rows are never selected', function () use ($orig, $corr) {
    seedCache($orig, $corr);
    $done = paceLog($orig, $corr);
    $done->update(['metadata' => array_merge($done->metadata, ['rechecked_at' => now()->toIso8601String(), 'recheck_result' => 'miss'])]);
    paceLog($orig, $corr, source: 'local_cache'); // native cache hit — not a fedex_api row

    $this->artisan('pace:recheck-cache')->expectsOutputToContain('No un-rechecked fedex_api')->assertSuccessful();

    expect($done->refresh()->metadata['recheck_result'])->toBe('miss'); // untouched
});

test('--limit bounds the batch; remainder left for a later pass', function () use ($orig, $corr) {
    seedCache($orig, $corr);
    foreach (range(1, 3) as $i) {
        paceLog(['address1' => "{$i} MAIN ST", 'city' => 'X', 'state' => 'KS', 'zip' => '67460', 'country' => 'US'], $corr);
    }

    $this->artisan('pace:recheck-cache', ['--limit' => 2])->assertSuccessful();

    expect(SystemLog::where('type', 'pace_address_correction')->whereNull('metadata->rechecked_at')->count())->toBe(1);
});
