<?php

use App\Models\Address;
use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Services\AddressValidationService;
use App\Services\Carriers\CarrierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A fake validation driver registered through the config registry, so the unified flow can be tested
 * end to end without hitting a real carrier API. Behaviour is scripted per slug via static maps.
 */
class FakeValidatorDriver implements CarrierInterface
{
    /** @var array<int, array{slug: string, input_address_1: ?string}> */
    public static array $log = [];

    /** @var array<string, bool> */
    public static array $throwFor = [];

    /** @var array<string, array<string, mixed>> */
    public static array $returnFor = [];

    /** @var array<string, int> batch calls per slug — proves bulk (one call per carrier group) */
    public static array $batchCalls = [];

    protected Carrier $carrier;

    public function setCarrier(Carrier $carrier): CarrierInterface
    {
        $this->carrier = $carrier;

        return $this;
    }

    public function validateAddress(Address $address): Address
    {
        $slug = $this->carrier->slug;
        self::$log[] = ['slug' => $slug, 'input_address_1' => $address->input_address_1];

        if (self::$throwFor[$slug] ?? false) {
            throw new RuntimeException("{$slug} down");
        }
        if (array_key_exists($slug, self::$returnFor)) {
            $address->update(self::$returnFor[$slug]);
        }

        return $address;
    }

    public function validateBatch(array $addresses): array
    {
        self::$batchCalls[$this->carrier->slug] = (self::$batchCalls[$this->carrier->slug] ?? 0) + 1;

        return array_map(fn (Address $a): Address => $this->validateAddress($a), $addresses);
    }

    public function testConnection(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSlug(): string
    {
        return $this->carrier->slug;
    }
}

beforeEach(function () {
    FakeValidatorDriver::$log = [];
    FakeValidatorDriver::$throwFor = [];
    FakeValidatorDriver::$returnFor = [];
    FakeValidatorDriver::$batchCalls = [];
    config([
        'address_validation.drivers.primary' => FakeValidatorDriver::class,
        'address_validation.drivers.backup' => FakeValidatorDriver::class,
    ]);
    Carrier::factory()->create(['slug' => 'primary', 'name' => 'Primary', 'is_active' => true]);
    Carrier::factory()->create(['slug' => 'backup', 'name' => 'Backup', 'is_active' => true]);
});

function vfcAddress(string $addr1 = '123 Main St', string $city = 'Wichita', string $postal = '67209'): Address
{
    return Address::create([
        'input_address_1' => $addr1, 'input_city' => $city, 'input_state' => 'KS',
        'input_postal' => $postal, 'input_country' => 'US', 'validation_status' => 'pending', 'source' => 'api',
    ]);
}

function vfcValid(string $addr1, ?bool $residential = true): array
{
    return [
        'output_address_1' => $addr1, 'output_city' => 'WICHITA', 'output_state' => 'KS',
        'output_postal' => '67209', 'output_country' => 'US', 'validation_status' => 'valid',
        'is_residential' => $residential, 'validated_at' => now(),
    ];
}

function vfcSeedCache(): void
{
    $ca = CorrectedAddress::create([
        'address_1' => '456 CACHED AVE', 'city' => 'WICHITA', 'state' => 'KS', 'postal' => '67210',
        'country' => 'US', 'address_hash' => hash('sha256', '456 CACHED AVE|67210'), 'first_seen_at' => now(),
    ]);
    AddressVariant::create([
        'corrected_address_id' => $ca->id,
        'input_address_1' => '123 Main St', 'input_city' => 'Wichita', 'input_state' => 'KS',
        'input_postal' => CorrectedAddress::normalizePostal('67209'), 'input_country' => 'US',
        'input_hash' => AddressVariant::computeHash('123 Main St', 'Wichita', 'KS', '67209', 'US'),
        'is_active' => true, 'times_seen' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

test('cache miss: validates the RAW input against the primary carrier; carrier result wins', function () {
    FakeValidatorDriver::$returnFor['primary'] = vfcValid('123 MAIN ST');

    $out = (new AddressValidationService)->validateForCarrier(vfcAddress(), 'primary', ['backup']);

    expect($out->output_address_1)->toBe('123 MAIN ST')
        ->and($out->is_residential)->toBeTrue()
        ->and($out->validation_source)->toBe('primary_api')
        ->and(FakeValidatorDriver::$log[0]['input_address_1'])->toBe('123 Main St'); // raw input sent
});

test('cache hit: the carrier is handed the CACHED form, and the original input is preserved', function () {
    vfcSeedCache();
    FakeValidatorDriver::$returnFor['primary'] = vfcValid('456 CACHED AVE', residential: false);

    $out = (new AddressValidationService)->validateForCarrier(vfcAddress(), 'primary', ['backup']);

    expect(FakeValidatorDriver::$log[0]['input_address_1'])->toBe('456 CACHED AVE') // cached form fed to carrier
        ->and($out->input_address_1)->toBe('123 Main St')                           // original input restored
        ->and($out->output_address_1)->toBe('456 CACHED AVE')
        ->and($out->validation_source)->toBe('primary_api');
});

test('primary carrier down: falls through to the fallback carrier', function () {
    FakeValidatorDriver::$throwFor['primary'] = true;
    FakeValidatorDriver::$returnFor['backup'] = vfcValid('FALLBACK ST');

    $out = (new AddressValidationService)->validateForCarrier(vfcAddress(), 'primary', ['backup']);

    expect($out->output_address_1)->toBe('FALLBACK ST')
        ->and($out->validation_source)->toBe('backup_api')
        ->and(collect(FakeValidatorDriver::$log)->pluck('slug')->all())->toBe(['primary', 'backup']);
});

test('all carriers down + cache hit: uses the cached form, residential left unknown', function () {
    vfcSeedCache();
    FakeValidatorDriver::$throwFor['primary'] = true;
    FakeValidatorDriver::$throwFor['backup'] = true;

    $out = (new AddressValidationService)->validateForCarrier(vfcAddress(), 'primary', ['backup']);

    expect($out->output_address_1)->toBe('456 CACHED AVE')
        ->and($out->validation_source)->toBe('local_cache')
        ->and($out->is_residential)->toBeNull();
});

test('all carriers down + no cache: address is left unvalidated', function () {
    FakeValidatorDriver::$throwFor['primary'] = true;
    FakeValidatorDriver::$throwFor['backup'] = true;

    $out = (new AddressValidationService)->validateForCarrier(vfcAddress(), 'primary', ['backup']);

    expect($out->output_address_1)->toBeNull();
});

test('unknown primary carrier (no driver) drops straight to the fallback list', function () {
    FakeValidatorDriver::$returnFor['backup'] = vfcValid('BK');

    $out = (new AddressValidationService)->validateForCarrier(vfcAddress(), 'callcsr', ['backup']);

    expect($out->validation_source)->toBe('backup_api')
        ->and(collect(FakeValidatorDriver::$log)->pluck('slug')->all())->toBe(['backup']); // primary never called
});

test('batch: a cache-miss set is validated in ONE bulk carrier call', function () {
    FakeValidatorDriver::$returnFor['primary'] = vfcValid('CORRECTED');

    $out = (new AddressValidationService)->validateBatchForCarrier(
        [vfcAddress(), vfcAddress('999 Elm St', 'Derby', '67037')], 'primary', ['backup']
    );

    expect(collect($out)->pluck('validation_source')->all())->toBe(['primary_api', 'primary_api'])
        ->and(FakeValidatorDriver::$batchCalls['primary'])->toBe(1); // one bulk call, not per-row
});

test('batch: a cache hit gets the cached form and a miss the raw input, in the same bulk call', function () {
    vfcSeedCache(); // 123 Main St -> 456 CACHED AVE
    FakeValidatorDriver::$returnFor['primary'] = vfcValid('X');
    $hit = vfcAddress();
    $miss = vfcAddress('999 Elm St', 'Derby', '67037');

    (new AddressValidationService)->validateBatchForCarrier([$hit, $miss], 'primary', ['backup']);

    $inputsSeen = collect(FakeValidatorDriver::$log)->pluck('input_address_1')->all();
    expect($inputsSeen)->toContain('456 CACHED AVE')          // cached form fed for the hit
        ->and($inputsSeen)->toContain('999 Elm St')           // raw input for the miss
        ->and(FakeValidatorDriver::$batchCalls['primary'])->toBe(1)
        ->and($hit->fresh()->input_address_1)->toBe('123 Main St'); // original input restored
});

test('batch: primary carrier down falls the whole set through to the fallback', function () {
    FakeValidatorDriver::$throwFor['primary'] = true;
    FakeValidatorDriver::$returnFor['backup'] = vfcValid('BK');

    $out = (new AddressValidationService)->validateBatchForCarrier(
        [vfcAddress(), vfcAddress('999 Elm St', 'Derby', '67037')], 'primary', ['backup']
    );

    expect(collect($out)->pluck('validation_source')->all())->toBe(['backup_api', 'backup_api'])
        ->and(FakeValidatorDriver::$batchCalls['backup'])->toBe(1);
});
