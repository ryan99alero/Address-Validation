<?php

use App\Filament\Pages\CorrectionHotspots;
use App\Models\Carrier;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $this->adcCat = DB::table('charge_categories')->insertGetId([
        'name' => 'Address Correction', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->invId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->ups->id, 'invoice_number' => 'INV1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function seedHotspotLine(int $invId, string $tracking, string $addr, string $zip, string $changeType, float $charge = 0): void
{
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $invId, 'tracking_number' => $tracking,
        'original_address_1' => $addr, 'original_city' => 'AUSTIN', 'original_state' => 'TX', 'original_postal' => $zip,
        'change_type' => $changeType, 'charge_amount' => $charge, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function seedAdcCharge(int $invId, int $carrierId, int $cat, string $tracking, float $amount): void
{
    DB::table('carrier_charges')->insert([
        'carrier_invoice_id' => $invId, 'carrier_id' => $carrierId, 'tracking_number' => $tracking,
        'charge_category_id' => $cat, 'raw_charge_description' => 'Address Correction', 'amount' => $amount,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('it clusters corrections and sums the real ADC fee from carrier_charges', function () {
    foreach (['T1', 'T2', 'T3', 'T4', 'T5'] as $i => $t) {
        seedHotspotLine($this->invId, $t, '123 MAIN STREET', '78701', $i === 4 ? 'zip_change' : 'address_change');
        seedAdcCharge($this->invId, $this->ups->id, $this->adcCat, $t, 20.20);
    }

    $rows = CorrectionHotspots::computeData(['min' => 5]);

    expect($rows)->toHaveCount(1);
    $r = $rows->first();
    expect($r['corrections'])->toBe(5)
        ->and($r['fees'])->toBe(101.00)              // 5 × $20.20 from carrier_charges, not the $0 line
        ->and($r['avg_fee'])->toBe(20.20)
        ->and($r['carriers'])->toBe('UPS')
        ->and($r['main_issue'])->toBe('Address Change'); // dominant change_type (4 of 5)
});

test('the min-corrections filter drops small clusters', function () {
    foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $t) {
        seedHotspotLine($this->invId, $t, '500 BIG BLVD', '78701', 'address_change');
    }
    foreach (['B1', 'B2'] as $t) {
        seedHotspotLine($this->invId, $t, '900 SMALL CT', '78702', 'address_change');
    }

    $rows = CorrectionHotspots::computeData(['min' => 5]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['location'])->toBe('500 BIG BLVD');
});

test('fees fall back to the line charge_amount when there is no ADC charge', function () {
    foreach (['C1', 'C2', 'C3', 'C4', 'C5'] as $t) {
        seedHotspotLine($this->invId, $t, '77 FALLBACK RD', '78703', 'address_change', charge: 8.00);
    }

    $rows = CorrectionHotspots::computeData(['min' => 5]);

    expect($rows->first()['fees'])->toBe(40.00); // 5 × $8.00 line charge
});

test('the cache refreshes when new correction lines are imported', function () {
    foreach (['D1', 'D2', 'D3', 'D4', 'D5'] as $t) {
        seedHotspotLine($this->invId, $t, '10 CACHE WAY', '78704', 'address_change');
    }
    expect(CorrectionHotspots::computeData(['min' => 5])->first()['corrections'])->toBe(5);

    // A newly imported correction line changes the version stamp → recompute, not a stale cache hit.
    seedHotspotLine($this->invId, 'D6', '10 CACHE WAY', '78704', 'address_change');

    expect(CorrectionHotspots::computeData(['min' => 5])->first()['corrections'])->toBe(6);
});
