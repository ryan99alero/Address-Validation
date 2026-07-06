<?php

use App\Models\Carrier;
use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;
use App\Services\Invoices\ChargeCategoryResolver;

beforeEach(function () {
    $this->ups = Carrier::factory()->create(['slug' => 'ups']);
    $this->fedex = Carrier::factory()->create(['slug' => 'fedex']);

    $this->addressCorrection = ChargeCategory::create(['name' => 'Address Correction']);
    $this->fuel = ChargeCategory::create(['name' => 'Fuel Surcharge']);

    ChargeCodeMapping::create(['carrier_id' => $this->ups->id, 'match_type' => 'code', 'match_value' => 'ADC', 'charge_category_id' => $this->addressCorrection->id, 'priority' => 100]);
    ChargeCodeMapping::create(['carrier_id' => $this->fedex->id, 'match_type' => 'code', 'match_value' => 'ADDCOR', 'charge_category_id' => $this->addressCorrection->id, 'priority' => 100]);
    ChargeCodeMapping::create(['carrier_id' => null, 'match_type' => 'description', 'match_value' => 'Fuel Surcharge', 'charge_category_id' => $this->fuel->id, 'priority' => 50]);
});

test('different carrier codes normalize to the same canonical category', function () {
    $resolver = app(ChargeCategoryResolver::class);

    expect($resolver->resolve($this->ups->id, 'ADC', null))->toBe($this->addressCorrection->id);
    expect($resolver->resolve($this->fedex->id, 'ADDCOR', null))->toBe($this->addressCorrection->id);
});

test('falls back to a cross-carrier description pattern', function () {
    $resolver = app(ChargeCategoryResolver::class);

    expect($resolver->resolve($this->ups->id, 'FSC', 'Ground Fuel Surcharge'))->toBe($this->fuel->id);
    expect($resolver->resolve($this->fedex->id, null, 'FUEL SURCHARGE'))->toBe($this->fuel->id);
});

test('a carrier-specific code wins over a generic description match', function () {
    // A generic description rule that would also match the code's line.
    ChargeCodeMapping::create(['carrier_id' => null, 'match_type' => 'description', 'match_value' => 'Correction', 'charge_category_id' => $this->fuel->id, 'priority' => 10]);

    $resolver = app(ChargeCategoryResolver::class);

    expect($resolver->resolve($this->ups->id, 'ADC', 'Address Correction Charge'))
        ->toBe($this->addressCorrection->id);
});

test('a carrier code mapping does not match a different carrier', function () {
    $resolver = app(ChargeCategoryResolver::class);

    // FedEx's ADDCOR code should not resolve for a UPS line.
    expect($resolver->resolve($this->ups->id, 'ADDCOR', null))->toBeNull();
});

test('unknown charges resolve to null (uncategorized)', function () {
    $resolver = app(ChargeCategoryResolver::class);

    expect($resolver->resolve($this->ups->id, 'XYZ', 'Some Mystery Fee'))->toBeNull();
});

test('repeated resolves are memoized and stay consistent (incl. cached nulls)', function () {
    $resolver = new ChargeCategoryResolver;

    // Same inputs called many times (as a huge batch invoice does) must return the same result
    // every time — both the matched category and the cached-null unmatched case.
    for ($i = 0; $i < 3; $i++) {
        expect($resolver->resolve($this->fedex->id, null, 'FUEL SURCHARGE'))->toBe($this->fuel->id)
            ->and($resolver->resolve($this->ups->id, 'XYZ', 'Some Mystery Fee'))->toBeNull();
    }
});
