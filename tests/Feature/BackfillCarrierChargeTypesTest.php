<?php

use App\Jobs\RecategorizeChargesJob;
use App\Models\Carrier;
use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $this->fuel = ChargeCategory::create(['name' => 'Fuel Surcharge']);
    ChargeCodeMapping::create(['carrier_id' => null, 'match_type' => 'description', 'match_value' => 'Fuel Surcharge', 'charge_category_id' => $this->fuel->id, 'priority' => 50]);

    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->ups->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function seedBackfillCharge(int $invoiceId, int $carrierId, string $desc, string $sourceType, int $count, ?int $categoryId = null): void
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'carrier_id' => $carrierId, 'carrier_invoice_id' => $invoiceId,
            'raw_charge_description' => $desc, 'source_type' => $sourceType,
            'charge_category_id' => $categoryId, 'amount' => 1.00,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('carrier_charges')->insert($rows);
}

test('backfill seeds one crosswalk row per (carrier, description), recording its format', function () {
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'Fuel Surcharge', 'csv', 5);
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'Fuel Surcharge', 'pdf', 5);
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'Remote Area Surcharge', 'csv', 4);

    $this->artisan('charge-types:backfill', ['--min-lines' => 3, '--no-restamp' => true])->assertSuccessful();

    // "Fuel Surcharge" seen in both formats collapses to ONE row with both labels set.
    $fuel = CarrierChargeType::where('display_name', 'Fuel Surcharge')->sole();
    expect($fuel->csv_label)->toBe('Fuel Surcharge')
        ->and($fuel->pdf_label)->toBe('Fuel Surcharge')
        ->and($fuel->charge_category_id)->toBe($this->fuel->id); // seeded category = current resolver output

    // An unknown charge is seeded with a null category (the review worklist).
    $remote = CarrierChargeType::where('display_name', 'Remote Area Surcharge')->sole();
    expect($remote->csv_label)->toBe('Remote Area Surcharge')
        ->and($remote->pdf_label)->toBeNull()
        ->and($remote->charge_category_id)->toBeNull();
});

test('backfill excludes residuals, correction-prefix lines, and below-threshold charges', function () {
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'UPS charge (unclassified — review)', 'pdf', 10);
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'Address Correction Ground', 'pdf', 10);
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'Rare One-Off Fee', 'csv', 2);

    $this->artisan('charge-types:backfill', ['--min-lines' => 3, '--no-restamp' => true])->assertSuccessful();

    expect(CarrierChargeType::where('display_name', 'like', 'UPS charge%')->exists())->toBeFalse()
        ->and(CarrierChargeType::where('display_name', 'Address Correction Ground')->exists())->toBeFalse()
        ->and(CarrierChargeType::where('display_name', 'Rare One-Off Fee')->exists())->toBeFalse();
});

test('backfill is idempotent — a re-run adds no duplicates', function () {
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'Fuel Surcharge', 'csv', 5);

    $this->artisan('charge-types:backfill', ['--min-lines' => 3, '--no-restamp' => true])->assertSuccessful();
    $this->artisan('charge-types:backfill', ['--min-lines' => 3, '--no-restamp' => true])->assertSuccessful();

    expect(CarrierChargeType::where('display_name', 'Fuel Surcharge')->count())->toBe(1);
});

test('the recategorize job stamps category and charge_type_id onto existing charges', function () {
    seedBackfillCharge($this->invoiceId, $this->ups->id, 'New Custom Fee', 'csv', 3, categoryId: null);
    $type = CarrierChargeType::create(['carrier_id' => $this->ups->id, 'display_name' => 'New Custom Fee', 'csv_label' => 'New Custom Fee', 'charge_category_id' => $this->fuel->id]);

    $changed = RecategorizeChargesJob::run($this->ups->id, ['New Custom Fee']);

    expect($changed)->toBe(3);
    $charges = DB::table('carrier_charges')->where('raw_charge_description', 'New Custom Fee')->get();
    expect($charges)->toHaveCount(3);
    foreach ($charges as $c) {
        expect((int) $c->charge_category_id)->toBe($this->fuel->id)
            ->and((int) $c->charge_type_id)->toBe($type->id);
    }
});
