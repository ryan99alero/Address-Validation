<?php

use App\Models\Carrier;
use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;
use App\Services\Invoices\ChargeCategoryResolver;

beforeEach(function () {
    $this->ups = Carrier::factory()->create(['slug' => 'ups']);
    $this->fedex = Carrier::factory()->create(['slug' => 'fedex']);

    $this->base = ChargeCategory::create(['name' => 'Base Transportation']);
    $this->fuel = ChargeCategory::create(['name' => 'Fuel Surcharge']);
    $this->addressCorrection = ChargeCategory::create(['name' => 'Address Correction']);
});

test('an exact crosswalk row wins over a legacy description substring', function () {
    ChargeCodeMapping::create(['carrier_id' => null, 'match_type' => 'description', 'match_value' => 'Widget', 'charge_category_id' => $this->base->id, 'priority' => 50]);
    $type = CarrierChargeType::create(['display_name' => 'Widget Fee', 'csv_label' => 'Widget Fee', 'charge_category_id' => $this->fuel->id]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolveDetailed($this->ups->id, null, 'Widget Fee', 'csv'))
        ->toBe([$this->fuel->id, $type->id]);
});

test('the crosswalk matches per format: a pdf-only label does not match a csv charge', function () {
    $type = CarrierChargeType::create(['display_name' => 'Peak Charge', 'pdf_label' => 'Peak Charge', 'charge_category_id' => $this->fuel->id]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolve($this->ups->id, null, 'Peak Charge', 'pdf'))->toBe($this->fuel->id)
        // No csv_label → a CSV charge doesn't match, and there's no legacy fallback → null.
        ->and($resolver->resolve($this->ups->id, null, 'Peak Charge', 'csv'))->toBeNull()
        // Unknown source type tries both formats.
        ->and($resolver->resolveDetailed($this->ups->id, null, 'Peak Charge', null))->toBe([$this->fuel->id, $type->id]);
});

test('a crosswalk row with no category records the type but leaves the category null (review worklist)', function () {
    $type = CarrierChargeType::create(['display_name' => 'Mystery Fee', 'csv_label' => 'Mystery Fee', 'charge_category_id' => null]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolveDetailed($this->ups->id, null, 'Mystery Fee', 'csv'))->toBe([null, $type->id]);
});

test('an optional csv section code qualifier must also match', function () {
    CarrierChargeType::create(['display_name' => 'Shared', 'csv_label' => 'Shared', 'csv_code' => 'ISS', 'charge_category_id' => $this->fuel->id]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolve($this->ups->id, 'ISS', 'Shared', 'csv'))->toBe($this->fuel->id)
        // Same label, different section code → the qualifier fails and nothing else matches.
        ->and($resolver->resolve($this->ups->id, 'SCC', 'Shared', 'csv'))->toBeNull();
});

test('a contains-style crosswalk row matches a variable-tail description', function () {
    CarrierChargeType::create(['display_name' => 'Billing Adjustment', 'csv_label' => 'Billing Adjustment', 'match_style' => CarrierChargeType::MATCH_CONTAINS, 'charge_category_id' => $this->base->id]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolve($this->ups->id, null, 'Billing Adjustment for W/E 07/12', 'csv'))->toBe($this->base->id);
});

test('a carrier-specific crosswalk row beats a generic one', function () {
    CarrierChargeType::create(['carrier_id' => null, 'display_name' => 'Xfee', 'csv_label' => 'Xfee', 'charge_category_id' => $this->base->id]);
    CarrierChargeType::create(['carrier_id' => $this->ups->id, 'display_name' => 'Xfee', 'csv_label' => 'Xfee', 'charge_category_id' => $this->fuel->id]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolve($this->ups->id, null, 'Xfee', 'csv'))->toBe($this->fuel->id)
        // A different carrier only sees the generic row.
        ->and($resolver->resolve($this->fedex->id, null, 'Xfee', 'csv'))->toBe($this->base->id);
});

test('the correction-prefix rule still wins over a crosswalk row', function () {
    // A (mistaken) crosswalk row that would send this to Fuel must not override the flat-fee rule.
    CarrierChargeType::create(['display_name' => 'Address Correction Ground', 'csv_label' => 'Address Correction Ground', 'charge_category_id' => $this->fuel->id]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolve($this->ups->id, null, 'Address Correction Ground', 'csv'))->toBe($this->addressCorrection->id);
});

test('with no crosswalk rows, legacy resolution is unchanged', function () {
    ChargeCodeMapping::create(['carrier_id' => null, 'match_type' => 'description', 'match_value' => 'Fuel Surcharge', 'charge_category_id' => $this->fuel->id, 'priority' => 50]);

    $resolver = new ChargeCategoryResolver;

    expect($resolver->resolve($this->fedex->id, null, 'Ground Fuel Surcharge'))->toBe($this->fuel->id)
        ->and($resolver->resolve($this->ups->id, 'XYZ', 'Some Mystery Fee'))->toBeNull();
});
