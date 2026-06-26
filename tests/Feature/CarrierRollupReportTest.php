<?php

use App\Filament\Pages\CarrierComparison;
use App\Models\Carrier;
use App\Models\CarrierChargeRollup;
use App\Models\CarrierShipRollup;
use App\Models\ChargeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes carrier comparison from the rollup and sums the year range', function () {
    $ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $fuel = ChargeCategory::create(['name' => 'Fuel Surcharge']);

    foreach ([2024, 2025] as $year) {
        CarrierChargeRollup::create(['carrier_id' => $ups->id, 'charge_category_id' => $fuel->id, 'year' => $year, 'charge_count' => 50, 'total_amount' => 500, 'distinct_ships' => 25]);
        CarrierChargeRollup::create(['carrier_id' => $fedex->id, 'charge_category_id' => $fuel->id, 'year' => $year, 'charge_count' => 100, 'total_amount' => 1500, 'distinct_ships' => 40]);
        CarrierShipRollup::create(['carrier_id' => $ups->id, 'year' => $year, 'total_ships' => 25, 'aux_ships' => 25]);
        CarrierShipRollup::create(['carrier_id' => $fedex->id, 'year' => $year, 'total_ships' => 40, 'aux_ships' => 40]);
    }

    // Avg $/charge across both years: ups 1000/100=10, fedex 3000/200=15.
    $rows = CarrierComparison::computeData(['metric' => 'avg', 'basis' => 'nominal', 'year_from' => null, 'year_to' => null]);
    $fuelRow = collect($rows)->firstWhere('category', 'Fuel Surcharge');
    expect(round($fuelRow['ups'], 2))->toBe(10.0)
        ->and(round($fuelRow['fedex'], 2))->toBe(15.0)
        ->and($fuelRow['worse'])->toBe('FedEx');

    // Year filter to a single year halves the totals.
    $oneYear = CarrierComparison::computeData(['metric' => 'total', 'basis' => 'nominal', 'year_from' => 2025, 'year_to' => 2025]);
    expect((float) collect($oneYear)->firstWhere('category', 'Fuel Surcharge')['ups'])->toBe(500.0);
});
