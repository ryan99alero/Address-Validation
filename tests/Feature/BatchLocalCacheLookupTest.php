<?php

use App\Models\Address;
use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Services\AddressValidationService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->carrier = Carrier::factory()->create([
        'slug' => 'fedex',
        'is_active' => true,
    ]);
});

/**
 * Seed the invoice-derived cache: an original (bad) input mapped to a corrected address.
 */
function seedCorrection(array $original, array $corrected): void
{
    $result = CorrectedAddress::findOrCreateFromCorrection(
        $corrected['address_1'],
        null,
        null,
        $corrected['city'],
        $corrected['state'],
        $corrected['postal'],
        null,
        'us'
    );

    AddressVariant::createOrUpdateVariant(
        $result['address']->id,
        $original['address_1'],
        null,
        $original['city'],
        $original['state'],
        $original['postal'],
        'us'
    );
}

test('batch validation uses the local invoice cache and skips the carrier API on a hit', function () {
    Http::fake(); // any outbound call would be a failure of the DB-first path

    seedCorrection(
        ['address_1' => '123 Main St', 'city' => 'Memphis', 'state' => 'TN', 'postal' => '38103'],
        ['address_1' => '123 Main Street', 'city' => 'Memphis', 'state' => 'TN', 'postal' => '38103'],
    );

    $address = Address::factory()->create([
        'input_address_1' => '123 Main St',
        'input_city' => 'Memphis',
        'input_state' => 'TN',
        'input_postal' => '38103',
        'input_country' => 'US',
        'validation_status' => 'pending',
    ]);

    $results = app(AddressValidationService::class)->validateBatch([$address], 'fedex');

    $fresh = $address->fresh();
    expect($fresh->validation_status)->toBe('valid');
    expect($fresh->validation_source)->toBe(Address::SOURCE_LOCAL_CACHE);
    expect($fresh->output_city)->toBe('memphis'); // cache stores normalized values
    expect($results)->toHaveCount(1);

    Http::assertNothingSent();
});

test('addresses with no local match are left for the carrier API', function () {
    // No cache entry seeded → this address is a "miss".
    $address = Address::factory()->create([
        'input_address_1' => '999 Unknown Rd',
        'input_city' => 'Nowhere',
        'input_state' => 'TN',
        'input_postal' => '38000',
        'input_country' => 'US',
        'validation_status' => 'pending',
    ]);

    $service = app(AddressValidationService::class);

    // Disable cache so we exercise pure miss-routing without external calls,
    // by asserting the lookup returns the address untouched by local_cache.
    $lookup = AddressVariant::lookupBatch(collect([[
        'address_1' => $address->input_address_1,
        'city' => $address->input_city,
        'state' => $address->input_state,
        'postal' => $address->input_postal,
        'country' => 'US',
    ]]));

    expect($lookup['hits'])->toBeEmpty();
    expect($lookup['misses'])->toHaveCount(1);
});
