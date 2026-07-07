<?php

use App\Filament\Widgets\CostIntelligenceStats;
use App\Models\Carrier;
use App\Services\Analytics\CostAnalyticsService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);
    foreach ([
        [CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'Address Correction'],
        [CostAnalyticsService::CAT_BASE, 'Base Transportation'],
        [CostAnalyticsService::CAT_CREDIT, 'Discount / Credit'],
    ] as [$id, $name]) {
        DB::table('charge_categories')->insert(['id' => $id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('carrier_invoices')->insert(['id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV-1', 'invoice_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_charge_rollup')->insert(['carrier_id' => 1, 'charge_category_id' => CostAnalyticsService::CAT_BASE, 'year' => 2025, 'charge_count' => 1, 'total_amount' => 1, 'distinct_ships' => 1, 'created_at' => now(), 'updated_at' => now()]);

    // 2025 correction spend down vs 2024 => the widget should render the ▼ YoY delta.
    DB::table('carrier_charges')->insert([
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2024-05-01', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'amount' => 1000, 'tracking_number' => 'A', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-05-01', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'amount' => 400, 'tracking_number' => 'B', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

test('cost intelligence widget reads the page filter and shows a year-over-year delta', function () {
    Livewire::test(CostIntelligenceStats::class, ['pageFilters' => ['year' => 2025, 'month' => 0]])
        ->assertOk()
        ->assertSee('Address Correction Fees · 2025')
        ->assertSee('$400')
        ->assertSee('vs 2024'); // YoY comparison line rendered
});

test('cost intelligence widget shows all-time totals with no YoY delta for All years', function () {
    // 2024 $1,000 + 2025 $400 corrections => $1,400 all-time, and no "vs <year>" comparison line.
    Livewire::test(CostIntelligenceStats::class, ['pageFilters' => ['year' => 0, 'month' => 0]])
        ->assertOk()
        ->assertSee('Address Correction Fees · All years')
        ->assertSee('$1,400')
        ->assertDontSee('vs 2024');
});

test('cost intelligence widget respects a month filter', function () {
    Livewire::test(CostIntelligenceStats::class, ['pageFilters' => ['year' => 2025, 'month' => 5]])
        ->assertOk()
        ->assertSee('Address Correction Fees · May 2025');
});
