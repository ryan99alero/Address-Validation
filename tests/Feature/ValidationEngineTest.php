<?php

use App\Models\Address;
use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Services\AddressValidationService;
use App\Services\Carriers\CarrierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);
    $this->ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS', 'is_active' => true]);
});

/**
 * A fake carrier that stamps a fixed outcome on each address it's given.
 * $valid=true simulates a usable correction; false simulates a miss/invalid.
 */
function fakeCarrier(string $slug, bool $valid, string $tag = 'X'): CarrierInterface
{
    return new class($slug, $valid, $tag) implements CarrierInterface
    {
        public array $seen = [];

        public function __construct(public string $slug, public bool $valid, public string $tag) {}

        public function setCarrier(Carrier $carrier): self
        {
            return $this;
        }

        public function validateAddress(Address $address): Address
        {
            return $this->validateBatch([$address])[0];
        }

        public function validateBatch(array $addresses): array
        {
            foreach ($addresses as $address) {
                $this->seen[] = $address->id;
                $address->update([
                    'output_address_1' => $this->valid ? $this->tag.' '.$address->input_address_1 : null,
                    'output_city' => $this->valid ? $address->input_city : null,
                    'validation_status' => $this->valid ? Address::STATUS_VALID : Address::STATUS_INVALID,
                ]);
            }

            return $addresses;
        }

        public function testConnection(): bool
        {
            return true;
        }

        public function getName(): string
        {
            return ucfirst($this->slug);
        }

        public function getSlug(): string
        {
            return $this->slug;
        }
    };
}

it('maps engine keys to ordered carrier pipelines', function () {
    $service = new AddressValidationService;

    expect($service->enginePipeline('fedex'))->toBe(['fedex'])
        ->and($service->enginePipeline('ups'))->toBe(['ups'])
        ->and($service->enginePipeline('fedex_ups'))->toBe(['fedex', 'ups'])
        ->and($service->enginePipeline('ups_fedex'))->toBe(['ups', 'fedex']);
});

it('resolves a chain from the local cache without calling any carrier', function () {
    Http::fake(); // any outbound call fails the cache-first contract

    $corrected = CorrectedAddress::findOrCreateFromCorrection('123 Main Street', null, null, 'Memphis', 'TN', '38103', null, 'us');
    AddressVariant::createOrUpdateVariant($corrected['address']->id, '123 Main St', null, 'Memphis', 'TN', '38103', 'us');

    $address = Address::factory()->create([
        'input_address_1' => '123 Main St',
        'input_city' => 'Memphis',
        'input_state' => 'TN',
        'input_postal' => '38103',
        'input_country' => 'US',
        'validation_status' => 'pending',
    ]);

    // If createCarrierService is ever called, fail loudly.
    $service = Mockery::mock(AddressValidationService::class)->shouldAllowMockingProtectedMethods()->makePartial();
    $service->shouldReceive('createCarrierService')->never();

    $results = $service->validateBatchWithEngine([$address], 'fedex_ups');

    expect($results[0]->validation_source)->toBe(Address::SOURCE_LOCAL_CACHE)
        ->and($results[0]->validation_status)->toBe(Address::STATUS_VALID)
        ->and($results[0]->output_address_1)->not->toBeNull();
});

it('takes the first carrier when it returns a usable correction and does not call the second', function () {
    $address = Address::factory()->create([
        'input_address_1' => '1 Nowhere Rd',
        'input_city' => 'Testville',
        'input_state' => 'TN',
        'input_postal' => '38103',
        'validation_status' => 'pending',
    ]);

    $fedexFake = fakeCarrier('fedex', valid: true, tag: 'FDX');
    $upsFake = fakeCarrier('ups', valid: true, tag: 'UPS');

    $service = Mockery::mock(AddressValidationService::class)->shouldAllowMockingProtectedMethods()->makePartial();
    $service->useLocalCache(false);
    $service->shouldReceive('createCarrierService')
        ->andReturnUsing(fn (Carrier $c) => $c->slug === 'fedex' ? $fedexFake : $upsFake);

    $results = $service->validateBatchWithEngine([$address], 'fedex_ups');

    expect($results[0]->output_address_1)->toBe('FDX 1 Nowhere Rd')
        ->and($results[0]->validation_source)->toBe(Address::SOURCE_FEDEX_API)
        ->and($upsFake->seen)->toBeEmpty(); // second carrier never ran
});

it('falls through to the second carrier when the first misses', function () {
    $address = Address::factory()->create([
        'input_address_1' => '2 Nowhere Rd',
        'input_city' => 'Testville',
        'input_state' => 'TN',
        'input_postal' => '38103',
        'validation_status' => 'pending',
    ]);

    $fedexFake = fakeCarrier('fedex', valid: false); // miss
    $upsFake = fakeCarrier('ups', valid: true, tag: 'UPS'); // rescue

    $service = Mockery::mock(AddressValidationService::class)->shouldAllowMockingProtectedMethods()->makePartial();
    $service->useLocalCache(false);
    $service->shouldReceive('createCarrierService')
        ->andReturnUsing(fn (Carrier $c) => $c->slug === 'fedex' ? $fedexFake : $upsFake);

    $results = $service->validateBatchWithEngine([$address], 'fedex_ups');

    expect($fedexFake->seen)->toContain($address->id) // first carrier tried
        ->and($upsFake->seen)->toContain($address->id) // second carrier tried
        ->and($results[0]->output_address_1)->toBe('UPS 2 Nowhere Rd')
        ->and($results[0]->validation_source)->toBe(Address::SOURCE_UPS_API);
});
