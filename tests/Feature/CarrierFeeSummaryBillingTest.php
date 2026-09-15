<?php

use App\Filament\Pages\CarrierFeeSummary;
use App\Models\Carrier;
use App\Models\CarrierChargeRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Carrier Fee Summary now splits fees three ways off the rollup's billing_type dimension
 * (Prepaid / Collect / 3rd Party), replacing the old 2-way is_third_party.
 */
test('computeData filters the rollup by the 3-way billing_type', function () {
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $catId = DB::table('charge_categories')->insertGetId([
        'name' => 'Address Correction', 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['prepaid' => 100, 'collect' => 30, 'third_party' => 10] as $type => $amount) {
        CarrierChargeRollup::create([
            'carrier_id' => $fedex->id, 'charge_category_id' => $catId,
            'is_third_party' => $type === 'third_party', 'billing_type' => $type,
            'year' => 2026, 'charge_count' => 1, 'total_amount' => $amount, 'distinct_ships' => 1,
        ]);
    }

    $total = fn (array $filters): float => (float) (CarrierFeeSummary::computeData($filters)->first()['total'] ?? 0);

    expect($total(['scope' => 'all']))->toBe(140.0)
        ->and($total(['scope' => 'all', 'billing_type' => 'collect']))->toBe(30.0)
        ->and($total(['scope' => 'all', 'billing_type' => 'third_party']))->toBe(10.0)
        ->and($total(['scope' => 'all', 'billing_type' => 'prepaid']))->toBe(100.0);
});
