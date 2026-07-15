<?php

use App\Filament\Pages\CarrierFeeSummary;
use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierChargeRollup;
use App\Models\CarrierInvoice;
use App\Models\CartonCost;
use App\Models\ChargeCategory;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'ups']);
    $this->invoice = CarrierInvoice::create([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV1',
        'invoice_date' => '2026-07-01', 'filename' => 'inv1.csv',
    ]);
    $this->base = ChargeCategory::create(['name' => 'Base Transportation']);
    $this->fuel = ChargeCategory::create(['name' => 'Fuel Surcharge']);
});

function charge(string $tracking, int $categoryId, float $amount = 10): void
{
    CarrierCharge::create([
        'carrier_invoice_id' => test()->invoice->id, 'carrier_id' => test()->carrier->id,
        'tracking_number' => $tracking, 'charge_category_id' => $categoryId,
        'amount' => $amount, 'source_type' => 'csv',
    ]);
}

function paceCarton(string $tracking, ?bool $thirdParty): void
{
    CartonCost::create(['tracking_number' => $tracking, 'ship_cost' => 5, 'is_third_party' => $thirdParty]);
}

it('classifies charges: Pace flag wins, base-charge heuristic fills the gap', function () {
    // Pace says THIRD-PARTY even though a base charge is present (Pace overrides heuristic).
    paceCarton('PACE_TP', true);
    charge('PACE_TP', $this->base->id);
    charge('PACE_TP', $this->fuel->id);

    // Pace says ON-ACCOUNT even though there's no base charge (Pace overrides heuristic).
    paceCarton('PACE_ACCT', false);
    charge('PACE_ACCT', $this->fuel->id);

    // No Pace flag → heuristic: no base charge ⇒ third-party.
    charge('HEUR_TP', $this->fuel->id);

    // No Pace flag → heuristic: has base charge ⇒ on-account.
    charge('HEUR_ACCT', $this->base->id);
    charge('HEUR_ACCT', $this->fuel->id);

    $thirdParty = CarrierCharge::thirdParty()->distinct()->pluck('tracking_number')->sort()->values()->all();
    $onAccount = CarrierCharge::onAccount()->distinct()->pluck('tracking_number')->sort()->values()->all();

    expect($thirdParty)->toBe(['HEUR_TP', 'PACE_TP'])
        ->and($onAccount)->toBe(['HEUR_ACCT', 'PACE_ACCT']);
});

it('works carrier-agnostically (FedEx tracking with no Pace + no base = third-party)', function () {
    $fedex = Carrier::factory()->create(['slug' => 'fedex']);
    $inv = CarrierInvoice::create(['carrier_id' => $fedex->id, 'invoice_number' => 'F1', 'invoice_date' => '2026-07-01', 'filename' => 'f.csv']);
    CarrierCharge::create(['carrier_invoice_id' => $inv->id, 'carrier_id' => $fedex->id, 'tracking_number' => 'FDX1', 'charge_category_id' => $this->fuel->id, 'amount' => 3, 'source_type' => 'pdf']);

    expect(CarrierCharge::thirdParty()->pluck('tracking_number')->all())->toContain('FDX1');
});

it('excludes account-level fees with no tracking number from both buckets', function () {
    charge('', $this->fuel->id); // empty tracking = account-level fee
    CarrierCharge::where('tracking_number', '')->update(['tracking_number' => null]);

    expect(CarrierCharge::thirdParty()->count())->toBe(0)
        ->and(CarrierCharge::onAccount()->count())->toBe(0);
});

it('splits the Carrier Fee Summary rollup by billing type', function () {
    CarrierChargeRollup::create([
        'carrier_id' => $this->carrier->id, 'charge_category_id' => $this->fuel->id, 'is_third_party' => true,
        'year' => 2026, 'charge_count' => 10, 'total_amount' => 100, 'distinct_ships' => 5,
    ]);
    CarrierChargeRollup::create([
        'carrier_id' => $this->carrier->id, 'charge_category_id' => $this->fuel->id, 'is_third_party' => false,
        'year' => 2026, 'charge_count' => 20, 'total_amount' => 500, 'distinct_ships' => 8,
    ]);

    $total = fn (array $filters): float => (float) collect(CarrierFeeSummary::computeData($filters))->sum('total');

    expect($total(['scope' => 'all']))->toBe(600.0)                                    // both
        ->and($total(['scope' => 'all', 'billing_type' => 'third_party']))->toBe(100.0) // TP only
        ->and($total(['scope' => 'all', 'billing_type' => 'on_account']))->toBe(500.0); // on-account only
});

it('interprets Pace thirdPartyCharges values into a boolean on sync', function () {
    app(CartonCostSyncService::class)->upsert([
        ['tracking_number' => 'A', 'is_third_party' => 'true'],
        ['tracking_number' => 'B', 'is_third_party' => 'No'],
        ['tracking_number' => 'C', 'is_third_party' => '1'],
        ['tracking_number' => 'D', 'is_third_party' => 0],
        ['tracking_number' => 'E', 'is_third_party' => ''],   // unknown → null
        ['tracking_number' => 'F', 'is_third_party' => 12.50], // non-zero amount → true
    ]);

    expect(CartonCost::where('tracking_number', 'A')->value('is_third_party'))->toBeTrue()
        ->and(CartonCost::where('tracking_number', 'B')->value('is_third_party'))->toBeFalse()
        ->and(CartonCost::where('tracking_number', 'C')->value('is_third_party'))->toBeTrue()
        ->and(CartonCost::where('tracking_number', 'D')->value('is_third_party'))->toBeFalse()
        ->and(CartonCost::where('tracking_number', 'E')->value('is_third_party'))->toBeNull()
        ->and(CartonCost::where('tracking_number', 'F')->value('is_third_party'))->toBeTrue();
});
