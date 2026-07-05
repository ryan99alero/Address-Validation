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
        [CostAnalyticsService::CAT_CREDIT, 'Discount / Credit'],
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

test('yearly totals compute accessorial load net of credits', function () {
    // base $8,000 (100 ships) + $2,000 accessorials − $1,000 credit => total $9,000.
    // accessorial ($2,000) is net of the credit; load = 2000/9000 = 22.2%.
    seedRollup(1, 2025, CostAnalyticsService::CAT_BASE, 8000, 100);
    seedRollup(1, 2025, CostAnalyticsService::CAT_ADDRESS_CORRECTION, 500, 20);
    seedRollup(1, 2025, 2 /* fuel */, 1500, 90);
    seedRollup(1, 2025, CostAnalyticsService::CAT_CREDIT, -1000, 100);

    $latest = app(CostAnalyticsService::class)->latestYear();

    expect($latest->total)->toBe(9000.0)
        ->and($latest->base)->toBe(8000.0)
        ->and($latest->credit)->toBe(-1000.0)
        ->and($latest->accessorial)->toBe(2000.0) // total − base − credit
        ->and($latest->load_pct)->toBe(22.2)
        ->and($latest->cost_per_ship)->toBe(90.0)
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
