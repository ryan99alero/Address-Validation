<?php

use App\Models\Carrier;
use App\Services\Analytics\CostAnalyticsService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);
    foreach ([
        [CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'Address Correction'],
        [2, 'Fuel Surcharge'],
        [CostAnalyticsService::CAT_BASE, 'Base Transportation'],
    ] as [$id, $name]) {
        DB::table('charge_categories')->insert(['id' => $id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
});

function seedRollup(int $carrierId, int $year, ?int $categoryId, float $amount, int $ships): void
{
    DB::table('carrier_charge_rollup')->insert([
        'carrier_id' => $carrierId, 'charge_category_id' => $categoryId, 'year' => $year,
        'charge_count' => $ships, 'total_amount' => $amount, 'distinct_ships' => $ships,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('yearly totals compute accessorial load and cost per shipment', function () {
    // 2025: base $8,000 over 100 ships + $2,000 accessorials => total 10,000, load 20%, cps $100
    seedRollup(1, 2025, CostAnalyticsService::CAT_BASE, 8000, 100);
    seedRollup(1, 2025, CostAnalyticsService::CAT_ADDRESS_CORRECTION, 500, 20);
    seedRollup(1, 2025, 2 /* fuel */, 1500, 90);

    $latest = app(CostAnalyticsService::class)->latestYear();

    expect($latest->year)->toBe(2025)
        ->and($latest->total)->toBe(10000.0)
        ->and($latest->base)->toBe(8000.0)
        ->and($latest->accessorial)->toBe(2000.0)
        ->and($latest->load_pct)->toBe(20.0)
        ->and($latest->ships)->toBe(100)
        ->and($latest->cost_per_ship)->toBe(100.0)
        ->and($latest->correction)->toBe(500.0);
});

test('category mix excludes base transport and orders by spend', function () {
    seedRollup(1, 2025, CostAnalyticsService::CAT_BASE, 8000, 100); // excluded
    seedRollup(1, 2025, 2 /* fuel */, 1500, 90);
    seedRollup(1, 2025, CostAnalyticsService::CAT_ADDRESS_CORRECTION, 500, 20);

    $mix = app(CostAnalyticsService::class)->categoryMix(2025);

    expect($mix->pluck('category')->all())->not->toContain('Base Transportation');
    expect($mix->first()->total)->toBe(1500.0); // fuel largest
});
