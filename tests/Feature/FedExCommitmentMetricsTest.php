<?php

use App\Models\Carrier;
use App\Models\FedExCommitmentSetting;
use App\Services\Fedex\CommitmentMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $this->baseCat = DB::table('charge_categories')->insertGetId([
        'name' => 'Base Transportation', 'abbreviation' => 'BASE', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-06-01',
        'charges_reconciled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Day-count = calendar keeps the denominator deterministic (no holiday math) for these assertions.
    FedExCommitmentSetting::create(['day_count_mode' => 'calendar']);
});

function shipment(string $tracking, ?string $service): void
{
    DB::table('carrier_shipments')->insert([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'tracking_number' => $tracking, 'service' => $service, 'source_type' => 'csv',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function baseCharge(string $tracking, float $amount, string $shipDate = '2026-06-02'): void
{
    DB::table('carrier_charges')->insert([
        'carrier_id' => test()->carrier->id, 'carrier_invoice_id' => test()->invoiceId,
        'charge_category_id' => test()->baseCat, 'tracking_number' => $tracking,
        'amount' => $amount, 'ship_date' => $shipDate, 'source_type' => 'csv',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('buckets base transportation by resolved service and computes the three metrics', function () {
    shipment('A1', 'FedEx 2Day');                 // express
    shipment('B1', 'Ground');                     // ground (CSV shorthand)
    shipment('C1', 'FedEx International Economy'); // unclassified (excluded intl)
    baseCharge('A1', 250.00);
    baseCharge('A1', 150.00); // second Express package, same tracking
    baseCharge('B1', 30.00);
    baseCharge('C1', 500.00);

    $report = app(CommitmentMetricsService::class)->rangeReport('2026-06-01', '2026-06-03');

    expect($report['express']['packages'])->toBe(2)
        ->and($report['express']['revenue'])->toBe(400.0)
        ->and($report['express']['days'])->toBe(3) // calendar days, inclusive
        ->and($report['express']['metrics']['avg_charge_per_package']['actual'])->toBe(200.0)
        ->and($report['ground']['packages'])->toBe(1)
        ->and($report['ground']['revenue'])->toBe(30.0)
        ->and($report['unclassified']['packages'])->toBe(1)
        ->and($report['unclassified']['revenue'])->toBe(500.0)
        ->and($report['unclassified']['services'])->toHaveKey('FedEx International Economy');
});

test('exact-match only — "FedEx Ground Economy" is NOT counted as Ground', function () {
    shipment('G1', 'FedEx Ground');
    shipment('E1', 'FedEx Ground Economy'); // substring of "FedEx Ground" but a different product
    baseCharge('G1', 20.00);
    baseCharge('E1', 5.00);

    $report = app(CommitmentMetricsService::class)->rangeReport('2026-06-01', '2026-06-03');

    expect($report['ground']['packages'])->toBe(1)
        ->and($report['unclassified']['services'])->toHaveKey('FedEx Ground Economy')
        ->and($report['unclassified']['services'])->not->toHaveKey('FedEx Ground');
});

test('Home Delivery is included in Ground by default and excludable via the toggle', function () {
    shipment('H1', 'Home Delivery');
    baseCharge('H1', 25.00);

    $included = app(CommitmentMetricsService::class)->rangeReport('2026-06-01', '2026-06-03');
    expect($included['ground']['packages'])->toBe(1);

    FedExCommitmentSetting::instance()->update(['include_home_delivery' => false]);
    $excluded = app(CommitmentMetricsService::class)->rangeReport('2026-06-01', '2026-06-03');
    expect($excluded['ground']['packages'])->toBe(0)
        ->and($excluded['unclassified']['services'])->toHaveKey('Home Delivery');
});

test('div-by-zero guards: zero packages → charge/package is nodata, avg/day is zero not NaN', function () {
    // No shipments at all in range.
    $report = app(CommitmentMetricsService::class)->rangeReport('2026-06-01', '2026-06-03');

    expect($report['express']['metrics']['avg_charge_per_package']['state'])->toBe('nodata')
        ->and($report['express']['metrics']['avg_charge_per_package']['actual'])->toBeNull()
        ->and($report['express']['metrics']['avg_daily_packages']['actual'])->toBe(0.0);
});

test('pass/fail state: below target is red, comfortably above is green', function () {
    shipment('A1', 'FedEx 2Day');
    baseCharge('A1', 300.00); // 1 package, $300 → charge/pkg 300 vs target 172.30 → green

    $report = app(CommitmentMetricsService::class)->rangeReport('2026-06-01', '2026-06-03');
    $m = $report['express']['metrics'];

    expect($m['avg_charge_per_package']['state'])->toBe('green')
        ->and($m['avg_charge_per_package']['pass'])->toBeTrue()
        ->and($m['avg_daily_packages']['state'])->toBe('red'); // 1/3 pkg/day << 2.90 target
});
