<?php

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;

beforeEach(function () {
    $this->address = Address::factory()->create();
});

it('parses smarty candidates from raw response', function () {
    $carrier = Carrier::factory()->create(['slug' => 'smarty']);

    $rawResponse = [
        [
            'delivery_line_1' => '123 MAIN ST',
            'delivery_line_2' => null,
            'components' => [
                'city_name' => 'SPRINGFIELD',
                'state_abbreviation' => 'IL',
                'zipcode' => '62701',
                'plus4_code' => '1234',
            ],
            'metadata' => [
                'rdi' => 'Residential',
            ],
            'analysis' => [
                'dpv_match_code' => 'Y',
            ],
        ],
        [
            'delivery_line_1' => '125 MAIN ST',
            'delivery_line_2' => null,
            'components' => [
                'city_name' => 'SPRINGFIELD',
                'state_abbreviation' => 'IL',
                'zipcode' => '62701',
                'plus4_code' => '5678',
            ],
            'metadata' => [
                'rdi' => 'Commercial',
            ],
            'analysis' => [
                'dpv_match_code' => 'S',
            ],
        ],
    ];

    $correction = AddressCorrection::factory()->create([
        'address_id' => $this->address->id,
        'carrier_id' => $carrier->id,
        'raw_response' => $rawResponse,
        'candidates_count' => 2,
    ]);

    $candidates = $correction->getAllCandidates();

    expect($candidates)->toHaveCount(2);
    expect($candidates[0]['address_line_1'])->toBe('123 MAIN ST');
    expect($candidates[0]['postal_code_ext'])->toBe('1234');
    expect($candidates[0]['classification'])->toBe('residential');
    expect($candidates[0]['confidence'])->toBe(1.0);
    expect($candidates[0]['dpv_match_code'])->toBe('Y');

    expect($candidates[1]['address_line_1'])->toBe('125 MAIN ST');
    expect($candidates[1]['classification'])->toBe('commercial');
    expect($candidates[1]['confidence'])->toBe(0.8);
});

it('parses ups candidates from raw response', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    $rawResponse = [
        'XAVResponse' => [
            'ValidAddressIndicator' => '',
            'Candidate' => [
                [
                    'AddressKeyFormat' => [
                        'AddressLine' => ['123 MAIN ST'],
                        'PoliticalDivision2' => 'SPRINGFIELD',
                        'PoliticalDivision1' => 'IL',
                        'PostcodePrimaryLow' => '62701',
                        'PostcodeExtendedLow' => '1234',
                        'CountryCode' => 'US',
                    ],
                    'AddressClassification' => [
                        'Code' => '2',
                    ],
                ],
                [
                    'AddressKeyFormat' => [
                        'AddressLine' => ['125 MAIN ST', 'APT 2'],
                        'PoliticalDivision2' => 'SPRINGFIELD',
                        'PoliticalDivision1' => 'IL',
                        'PostcodePrimaryLow' => '62701',
                        'PostcodeExtendedLow' => '5678',
                        'CountryCode' => 'US',
                    ],
                    'AddressClassification' => [
                        'Code' => '1',
                    ],
                ],
            ],
        ],
    ];

    $correction = AddressCorrection::factory()->create([
        'address_id' => $this->address->id,
        'carrier_id' => $carrier->id,
        'raw_response' => $rawResponse,
        'candidates_count' => 2,
    ]);

    $candidates = $correction->getAllCandidates();

    expect($candidates)->toHaveCount(2);
    expect($candidates[0]['address_line_1'])->toBe('123 MAIN ST');
    expect($candidates[0]['postal_code_ext'])->toBe('1234');
    expect($candidates[0]['classification'])->toBe('residential');
    expect($candidates[0]['confidence'])->toBe(1.0);

    expect($candidates[1]['address_line_1'])->toBe('125 MAIN ST');
    expect($candidates[1]['address_line_2'])->toBe('APT 2');
    expect($candidates[1]['classification'])->toBe('commercial');
});

it('parses fedex candidates from raw response', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);

    $rawResponse = [
        'output' => [
            'resolvedAddresses' => [
                [
                    'streetLinesToken' => ['123 MAIN ST'],
                    'city' => 'SPRINGFIELD',
                    'stateOrProvinceCode' => 'IL',
                    'postalCode' => '62701-1234',
                    'countryCode' => 'US',
                    'state' => 'VALID',
                    'classification' => 'RESIDENTIAL',
                ],
                [
                    'streetLinesToken' => ['125 MAIN ST'],
                    'city' => 'SPRINGFIELD',
                    'stateOrProvinceCode' => 'IL',
                    'postalCode' => '62701',
                    'countryCode' => 'US',
                    'state' => 'AMBIGUOUS',
                    'classification' => 'BUSINESS',
                ],
            ],
        ],
    ];

    $correction = AddressCorrection::factory()->create([
        'address_id' => $this->address->id,
        'carrier_id' => $carrier->id,
        'raw_response' => $rawResponse,
        'candidates_count' => 2,
    ]);

    $candidates = $correction->getAllCandidates();

    expect($candidates)->toHaveCount(2);
    expect($candidates[0]['address_line_1'])->toBe('123 MAIN ST');
    expect($candidates[0]['postal_code'])->toBe('62701');
    expect($candidates[0]['postal_code_ext'])->toBe('1234');
    expect($candidates[0]['classification'])->toBe('residential');
    expect($candidates[0]['confidence'])->toBe(1.0);
    expect($candidates[0]['fedex_state'])->toBe('VALID');

    expect($candidates[1]['address_line_1'])->toBe('125 MAIN ST');
    expect($candidates[1]['classification'])->toBe('commercial');
    expect($candidates[1]['confidence'])->toBe(0.5);
});

it('handles single ups candidate (associative array)', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    $rawResponse = [
        'XAVResponse' => [
            'ValidAddressIndicator' => '',
            'Candidate' => [
                'AddressKeyFormat' => [
                    'AddressLine' => '123 MAIN ST',
                    'PoliticalDivision2' => 'SPRINGFIELD',
                    'PoliticalDivision1' => 'IL',
                    'PostcodePrimaryLow' => '62701',
                    'CountryCode' => 'US',
                ],
            ],
        ],
    ];

    $correction = AddressCorrection::factory()->create([
        'address_id' => $this->address->id,
        'carrier_id' => $carrier->id,
        'raw_response' => $rawResponse,
        'candidates_count' => 1,
    ]);

    $candidates = $correction->getAllCandidates();

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]['address_line_1'])->toBe('123 MAIN ST');
});

it('formats postal code with extension', function () {
    expect(AddressCorrection::formatPostalCode('62701', '1234'))->toBe('62701-1234');
    expect(AddressCorrection::formatPostalCode('62701', null))->toBe('62701');
    expect(AddressCorrection::formatPostalCode(null, null))->toBe('');
});
