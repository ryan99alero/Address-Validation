<?php

use App\Filament\Widgets\CorrectionImpactChart;
use App\Models\Carrier;
use App\Services\Analytics\CostAnalyticsService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);

    foreach ([
        [CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'Address Correction'],
        [CostAnalyticsService::CAT_BASE, 'Base Transportation'],
        [14, 'Residential Surcharge'],
    ] as [$id, $name]) {
        DB::table('charge_categories')->insert(['id' => $id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('carrier_invoices')->insert(['id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV-1', 'invoice_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()]);

    // 2025: $850 base + $100 address-correction + $50 residential-reclass = $1,000 total,
    // $150 avoidable => 15% correctable fee load.
    DB::table('carrier_charges')->insert([
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-01-05', 'charge_category_id' => CostAnalyticsService::CAT_BASE, 'driver' => 'normal', 'amount' => 850, 'tracking_number' => 'A', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-03-10', 'charge_category_id' => CostAnalyticsService::CAT_ADDRESS_CORRECTION, 'driver' => 'address_correction', 'amount' => 100, 'tracking_number' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'invoice_date' => '2025-06-20', 'charge_category_id' => 14, 'driver' => 'residential_reclass', 'amount' => 50, 'tracking_number' => 'C', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

test('correctableFeeLoad sums avoidable fees and the load percentage', function () {
    $load = app(CostAnalyticsService::class)->correctableFeeLoad(2025);

    expect($load->total)->toBe(1000.0)
        ->and($load->avoidable)->toBe(150.0)   // 100 address-correction + 50 residential-reclass
        ->and($load->load_pct)->toBe(15.0)
        ->and($load->count)->toBe(2);
});

test('correctableFeeLoadSeries buckets the load by month within a year', function () {
    $series = app(CostAnalyticsService::class)->correctableFeeLoadSeries(2025)
        ->keyBy('label');

    expect($series['01']->load_pct)->toBe(0.0)     // base only
        ->and($series['03']->avoidable)->toBe(100.0)
        ->and($series['06']->avoidable)->toBe(50.0);
});

test('the Correctable Fee Load widget renders the headline load percentage', function () {
    Livewire::test(CorrectionImpactChart::class, ['pageFilters' => ['year' => 2025, 'month' => 0]])
        ->assertOk()
        ->assertSee('Correctable Fee Load')
        ->assertSee('15% of spend');
});
