<?php

use App\Models\Carrier;
use App\Models\ShipViaCode;

beforeEach(function () {
    // Create carriers for testing
    $this->fedexCarrier = Carrier::factory()->create([
        'name' => 'FedEx',
        'slug' => 'fedex',
        'is_active' => true,
    ]);

    $this->upsCarrier = Carrier::factory()->create([
        'name' => 'UPS',
        'slug' => 'ups',
        'is_active' => true,
    ]);
});

describe('ShipViaCode lookup', function () {
    it('finds by exact user code', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->fedexGround()
            ->withCode('5137')
            ->create();

        $result = ShipViaCode::lookup('5137');

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($shipViaCode->id);
    });

    it('finds by carrier code case-insensitive', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->create([
                'carrier_code' => 'FDG',
                'service_type' => 'FEDEX_GROUND',
                'service_name' => 'FedEx Ground',
            ]);

        // Uppercase
        $result = ShipViaCode::lookup('FDG');
        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($shipViaCode->id);

        // Lowercase
        $result = ShipViaCode::lookup('fdg');
        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($shipViaCode->id);
    });

    it('finds by alternate codes', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->fedexGround()
            ->withAlternateCodes(['FDXG', 'GROUND', 'FXG'])
            ->create();

        // Check each alternate code
        expect(ShipViaCode::lookup('FDXG'))->not->toBeNull()
            ->and(ShipViaCode::lookup('FDXG')->id)->toBe($shipViaCode->id);

        expect(ShipViaCode::lookup('GROUND'))->not->toBeNull()
            ->and(ShipViaCode::lookup('GROUND')->id)->toBe($shipViaCode->id);

        expect(ShipViaCode::lookup('FXG'))->not->toBeNull()
            ->and(ShipViaCode::lookup('FXG')->id)->toBe($shipViaCode->id);
    });

    it('finds alternate codes case-insensitive', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->fedexGround()
            ->withAlternateCodes(['FDXG'])
            ->create();

        // Lowercase should also work
        $result = ShipViaCode::lookup('fdxg');
        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($shipViaCode->id);
    });

    it('finds by service type', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->create([
                'carrier_code' => 'FDG',
                'service_type' => 'FEDEX_GROUND',
                'service_name' => 'FedEx Ground',
            ]);

        $result = ShipViaCode::lookup('FEDEX_GROUND');

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($shipViaCode->id);
    });

    it('prioritizes user code over carrier code', function () {
        // Create two codes - one with user code matching, one with carrier code
        $userCodeMatch = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->withCode('FDG')
            ->create([
                'carrier_code' => 'OTHER',
                'service_name' => 'User Code Match',
            ]);

        $carrierCodeMatch = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->create([
                'code' => null,
                'carrier_code' => 'FDG',
                'service_name' => 'Carrier Code Match',
            ]);

        // Should find user code match first
        $result = ShipViaCode::lookup('FDG');

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($userCodeMatch->id);
    });

    it('ignores inactive ship via codes', function () {
        ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->fedexGround()
            ->inactive()
            ->create();

        $result = ShipViaCode::lookup('FDG');

        expect($result)->toBeNull();
    });

    it('returns null for unknown codes', function () {
        $result = ShipViaCode::lookup('UNKNOWN_CODE');

        expect($result)->toBeNull();
    });

    it('uses carrier code map for fallback lookup', function () {
        // Create a ship via code that matches via the CARRIER_CODE_MAP
        $shipViaCode = ShipViaCode::factory()
            ->for($this->upsCarrier, 'carrier')
            ->create([
                'code' => null,
                'carrier_code' => null,
                'alternate_codes' => null,
                'service_type' => 'GND',
                'service_name' => 'UPS Ground',
            ]);

        // '03' maps to UPS GND in CARRIER_CODE_MAP
        $result = ShipViaCode::lookup('03');

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($shipViaCode->id);
    });
});

describe('ShipViaCode getAllLookupCodes', function () {
    it('returns all lookup codes including alternates', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->withCode('5137')
            ->withAlternateCodes(['FDXG', 'GROUND'])
            ->create([
                'carrier_code' => 'FDG',
                'service_type' => 'FEDEX_GROUND',
            ]);

        $codes = $shipViaCode->getAllLookupCodes();

        expect($codes)->toContain('5137')
            ->toContain('FDG')
            ->toContain('FDXG')
            ->toContain('GROUND')
            ->toContain('FEDEX_GROUND');
    });

    it('handles null fields gracefully', function () {
        $shipViaCode = ShipViaCode::factory()
            ->for($this->fedexCarrier, 'carrier')
            ->create([
                'code' => null,
                'carrier_code' => 'FDG',
                'alternate_codes' => null,
                'service_type' => null,
            ]);

        $codes = $shipViaCode->getAllLookupCodes();

        expect($codes)->toBe(['FDG']);
    });
});

describe('ShipViaCode service types', function () {
    it('returns FedEx service types', function () {
        $types = ShipViaCode::getServiceTypesForCarrier('fedex');

        expect($types)->toHaveKey('FEDEX_GROUND')
            ->toHaveKey('GROUND_HOME_DELIVERY')
            ->toHaveKey('FEDEX_EXPRESS_SAVER')
            ->toHaveKey('PRIORITY_OVERNIGHT');
    });

    it('returns UPS service types', function () {
        $types = ShipViaCode::getServiceTypesForCarrier('ups');

        expect($types)->toHaveKey('GND')
            ->toHaveKey('2DA')
            ->toHaveKey('NDA');
    });

    it('returns empty array for unknown carrier', function () {
        $types = ShipViaCode::getServiceTypesForCarrier('unknown');

        expect($types)->toBe([]);
    });
});
