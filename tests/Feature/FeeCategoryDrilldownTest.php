<?php

use App\Filament\Widgets\FeeCategoryMixChart;
use App\Models\Carrier;
use App\Services\Analytics\CostAnalyticsService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);

    foreach ([
        [CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'Address Correction'],
        [CostAnalyticsService::CAT_BASE, 'Base Transportation'],
    ] as [$id, $name]) {
        DB::table('charge_categories')->insert(['id' => $id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('carrier_invoices')->insert(['id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV-1', 'invoice_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_charge_rollup')->insert([
        ['carrier_id' => 1, 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'year' => 2024, 'charge_count' => 2, 'total_amount' => 150, 'distinct_ships' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'year' => 2025, 'charge_count' => 2, 'total_amount' => 100, 'distinct_ships' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('carrier_charges')->insert([
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2024-03-10', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'amount' => 100, 'tracking_number' => 'A', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2024-07-05', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'amount' => 50, 'tracking_number' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-03-01', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'amount' => 40, 'tracking_number' => 'C', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-08-06', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'amount' => 60, 'tracking_number' => 'D', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-01-01', 'charge_category_id' => CostAnalyticsService::CAT_BASE, 'amount' => 999, 'tracking_number' => 'E', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

test('categoryTimeSeries buckets one category by year, month, then day', function () {
    $svc = app(CostAnalyticsService::class);

    $byYear = $svc->categoryTimeSeries('Address Correction', null, null);
    expect($byYear->pluck('total', 'label')->all())->toEqual(['2024' => 150.0, '2025' => 100.0]);

    $byMonth = $svc->categoryTimeSeries('Address Correction', 2025, null);
    expect($byMonth->pluck('total', 'label')->all())->toEqual(['03' => 40.0, '08' => 60.0]);

    $byDay = $svc->categoryTimeSeries('Address Correction', 2025, 3);
    expect($byDay->pluck('total', 'label')->all())->toEqual(['01' => 40.0]);
});

test('categoryTimeSeries excludes charges outside the clicked category', function () {
    $svc = app(CostAnalyticsService::class);

    // Base transportation ($999) must never leak into an Address Correction drill.
    $byYear = $svc->categoryTimeSeries('Address Correction', null, null);
    expect($byYear->sum('total'))->toBe(250.0);
});

test('fee category chart drills from the mix into a per-year breakdown and back', function () {
    Livewire::test(FeeCategoryMixChart::class, ['pageFilters' => ['year' => 0, 'month' => 0]])
        ->assertOk()
        ->assertSee('Accessorial Spend by Category · All years')
        ->assertSee('Click a category to break it down over time.')
        ->call('drillIntoCategory', 'Address Correction')
        ->assertSet('drillCategory', 'Address Correction')
        ->assertSee('Address Correction · by Year')
        ->call('clearDrill')
        ->assertSet('drillCategory', null)
        ->assertSee('Accessorial Spend by Category · All years');
});

test('fee category chart drill granularity follows the period filter', function () {
    Livewire::test(FeeCategoryMixChart::class, ['pageFilters' => ['year' => 2025, 'month' => 0]])
        ->assertOk()
        ->call('drillIntoCategory', 'Address Correction')
        ->assertSee('Address Correction · 2025 by Month');
});
