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
        'environment' => 'sandbox',
        'timeout_seconds' => 30,
    ]);
    $this->carrier->setCredentials(['client_id' => 'test', 'client_secret' => 'test']);
    $this->carrier->save();

    // Invoice-cache correction: '123 Main St' -> '123 Main Street'
    $corrected = CorrectedAddress::findOrCreateFromCorrection(
        '123 Main Street', null, null, 'Memphis', 'TN', '38103', null, 'us'
    );
    AddressVariant::createOrUpdateVariant(
        $corrected['address']->id, '123 Main St', null, 'Memphis', 'TN', '38103', 'us'
    );
});

function fakeFedEx(array $resolvedStreet, string $city = 'MEMPHIS', string $state = 'TN', string $postal = '38103'): void
{
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer', 'expires_in' => 3600]),
        '*/address/v1/addresses/resolve' => Http::response([
            'output' => [
                'resolvedAddresses' => [[
                    'streetLinesToken' => $resolvedStreet,
                    'city' => $city,
                    'stateOrProvinceCode' => $state,
                    'postalCode' => $postal,
                    'classification' => 'BUSINESS',
                    'attributes' => [
                        'DPV' => 'true', 'Matched' => 'true', 'Resolved' => 'true',
                        'ZIP4Match' => 'true', 'ZIP11Match' => 'true', 'MultipleMatches' => 'false',
                    ],
                ]],
            ],
        ]),
    ]);
}

function makeAddress(): Address
{
    return Address::factory()->create([
        'input_address_1' => '123 Main St',
        'input_city' => 'Memphis',
        'input_state' => 'TN',
        'input_postal' => '38103',
        'input_country' => 'US',
        'validation_status' => 'pending',
    ]);
}

test('check-both flags needs_review with two candidates when DB and API disagree', function () {
    fakeFedEx(['999 DIFFERENT AVE']); // API says something other than the invoice DB

    $address = makeAddress();

    app(AddressValidationService::class)->validateBatch([$address], 'fedex', checkBoth: true);

    $fresh = $address->fresh();
    expect($fresh->validation_status)->toBe('needs_review');
    expect($fresh->validation_source)->toBeNull();
    expect($fresh->output_address_1)->toBeNull();

    $candidates = $fresh->candidates()->get();
    expect($candidates)->toHaveCount(2);
    expect($candidates->pluck('source')->all())->toContain('invoice_db', 'fedex_api');

    $api = $candidates->firstWhere('source', 'fedex_api');
    expect($api->address_1)->toBe('999 DIFFERENT AVE');
});

test('check-both accepts the address and purges candidates when DB and API agree', function () {
    fakeFedEx(['123 Main Street']); // API matches the invoice DB (normalized equal)

    $address = makeAddress();

    app(AddressValidationService::class)->validateBatch([$address], 'fedex', checkBoth: true);

    $fresh = $address->fresh();
    expect($fresh->validation_status)->toBe('valid');
    expect($fresh->validation_source)->toBe(Address::SOURCE_LOCAL_CACHE);
    expect($fresh->candidates()->count())->toBe(0);
});
