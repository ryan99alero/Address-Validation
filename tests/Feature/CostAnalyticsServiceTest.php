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
    DB::table('carrier_invoices')->insert([
        'id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV-1', 'invoice_date' => '2025-01-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);
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

function seedCharge(string $date, int $categoryId, float $amount, string $tracking): void
{
    DB::table('carrier_charges')->insert([
        'carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => $date,
        'charge_category_id' => $categoryId, 'amount' => $amount, 'tracking_number' => $tracking,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('period totals aggregate a single year live from charges', function () {
    seedCharge('2025-03-10', CostAnalyticsService::CAT_BASE, 8000, 'T1');
    seedCharge('2025-06-20', 2 /* fuel */, 1500, 'T1');
    seedCharge('2025-06-20', CostAnalyticsService::CAT_ADDRESS_CORRECTION, 500, 'T2');
    seedCharge('2025-09-01', CostAnalyticsService::CAT_CREDIT, -1000, 'T3');
    seedCharge('2024-06-20', CostAnalyticsService::CAT_BASE, 999, 'T9'); // other year, excluded

    $p = app(CostAnalyticsService::class)->periodTotals(2025);

    expect($p->total)->toBe(9000.0)
        ->and($p->base)->toBe(8000.0)
        ->and($p->credit)->toBe(-1000.0)
        ->and($p->accessorial)->toBe(2000.0)
        ->and($p->correction)->toBe(500.0)
        ->and($p->ships)->toBe(1) // one distinct base tracking (T1)
        ->and($p->load_pct)->toBe(22.2);
});

test('period totals with null year aggregate all time', function () {
    seedCharge('2024-03-10', CostAnalyticsService::CAT_BASE, 1000, 'T1');
    seedCharge('2025-03-10', CostAnalyticsService::CAT_BASE, 2000, 'T2');

    $p = app(CostAnalyticsService::class)->periodTotals(null);

    expect($p->total)->toBe(3000.0)->and($p->year)->toBeNull();
});

test('period totals with null year and a month aggregate that month across years', function () {
    seedCharge('2024-06-10', 2 /* fuel */, 300, 'T1');
    seedCharge('2025-06-10', 2 /* fuel */, 500, 'T2');
    seedCharge('2025-07-10', 2 /* fuel */, 999, 'T3'); // other month, excluded

    expect(app(CostAnalyticsService::class)->periodTotals(null, 6)->total)->toBe(800.0);
});

test('period category mix with null year covers all time', function () {
    seedCharge('2024-01-10', 2 /* fuel */, 400, 'T1');
    seedCharge('2025-01-10', 2 /* fuel */, 600, 'T2');
    seedCharge('2025-01-10', CostAnalyticsService::CAT_BASE, 5000, 'T2'); // base excluded

    $mix = app(CostAnalyticsService::class)->periodCategoryMix(null);

    expect($mix->first()->category)->toBe('Fuel Surcharge')->and($mix->first()->total)->toBe(1000.0);
});

test('period totals narrow to a single month', function () {
    seedCharge('2025-06-05', 2 /* fuel */, 300, 'T1');
    seedCharge('2025-07-05', 2 /* fuel */, 700, 'T2'); // different month, excluded

    $p = app(CostAnalyticsService::class)->periodTotals(2025, 6);

    expect($p->total)->toBe(300.0)->and($p->month)->toBe(6);
});

test('period totals return zeros for an empty period', function () {
    $p = app(CostAnalyticsService::class)->periodTotals(1999);

    expect($p->total)->toBe(0.0)->and($p->load_pct)->toBe(0.0)->and($p->ships)->toBe(0);
});

test('period category mix is period scoped and excludes base', function () {
    seedCharge('2025-01-10', CostAnalyticsService::CAT_BASE, 5000, 'T1'); // excluded
    seedCharge('2025-01-10', 2 /* fuel */, 900, 'T1');
    seedCharge('2025-01-10', CostAnalyticsService::CAT_ADDRESS_CORRECTION, 400, 'T2');
    seedCharge('2024-01-10', 2 /* fuel */, 8000, 'T9'); // other year

    $mix = app(CostAnalyticsService::class)->periodCategoryMix(2025);

    expect($mix->pluck('category')->all())->not->toContain('Base Transportation');
    expect($mix->first()->category)->toBe('Fuel Surcharge')->and($mix->first()->total)->toBe(900.0);
});

test('monthly totals break a single year into its months', function () {
    seedCharge('2025-02-10', 2 /* fuel */, 300, 'A');
    seedCharge('2025-02-20', CostAnalyticsService::CAT_BASE, 700, 'A');
    seedCharge('2025-05-10', 2 /* fuel */, 500, 'B');
    seedCharge('2024-05-10', 2 /* fuel */, 999, 'C'); // other year, excluded

    $rows = app(CostAnalyticsService::class)->monthlyTotals(2025);

    expect($rows->pluck('month')->all())->toBe([2, 5])
        ->and($rows->firstWhere('month', 2)->total)->toBe(1000.0)
        ->and($rows->firstWhere('month', 5)->total)->toBe(500.0)
        ->and($rows->first()->year)->toBe(2025);
});

test('daily totals break a single month into its days', function () {
    seedCharge('2025-06-03', 2 /* fuel */, 300, 'A');
    seedCharge('2025-06-03', CostAnalyticsService::CAT_BASE, 200, 'A');
    seedCharge('2025-06-20', 2 /* fuel */, 500, 'B');
    seedCharge('2025-07-03', 2 /* fuel */, 999, 'C'); // other month, excluded

    $rows = app(CostAnalyticsService::class)->dailyTotals(2025, 6);

    expect($rows->pluck('day')->all())->toBe([3, 20])
        ->and($rows->firstWhere('day', 3)->total)->toBe(500.0)
        ->and($rows->firstWhere('day', 20)->total)->toBe(500.0)
        ->and($rows->first()->month)->toBe(6);
});

test('yearly totals for a month give one point per year for that month only', function () {
    seedCharge('2024-06-10', 2 /* fuel */, 300, 'A');
    seedCharge('2024-07-10', 2 /* fuel */, 999, 'A'); // July — excluded
    seedCharge('2025-06-10', 2 /* fuel */, 500, 'B');

    $rows = app(CostAnalyticsService::class)->yearlyTotalsForMonth(6);

    expect($rows->pluck('year')->all())->toBe([2024, 2025])
        ->and($rows->pluck('total')->all())->toBe([300.0, 500.0])
        ->and($rows->first()->month)->toBe(6);
});

test('available years come from the rollup newest first', function () {
    seedRollup(1, 2023, CostAnalyticsService::CAT_BASE, 1, 1);
    seedRollup(1, 2025, CostAnalyticsService::CAT_BASE, 1, 1);
    seedRollup(1, 2024, CostAnalyticsService::CAT_BASE, 1, 1);

    expect(app(CostAnalyticsService::class)->availableYears())->toBe([2025, 2024, 2023]);
});
