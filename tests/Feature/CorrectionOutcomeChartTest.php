<?php

use App\Filament\Widgets\CorrectionOutcomeChart;
use App\Models\Carrier;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Analytics\CorrectionOutcomeService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function funnelCorrection(string $job, bool $changed): SystemLog
{
    return SystemLog::create([
        'category' => 'integration', 'type' => 'pace_address_correction', 'level' => 'info', 'status' => 'success',
        'summary' => 'x',
        'metadata' => ['job_number' => $job, 'changes' => $changed ? [['field' => 'zip', 'from' => '0', 'to' => '1']] : []],
    ]);
}

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);
    DB::table('charge_categories')->insert([
        ['id' => 1, 'name' => 'Address Correction', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 13, 'name' => 'Base Transportation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('carrier_invoices')->insert(['id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV1', 'invoice_date' => '2026-04-01', 'created_at' => now(), 'updated_at' => now()]);

    funnelCorrection('JFIX', true);    // fixed, will be Fee avoided (March)
    funnelCorrection('JFIX2', true);   // fixed, will be Charged + Billed back (March)
    funnelCorrection('JNOFIX', false); // no-change, will be Charged (no fix) (March)
    funnelCorrection('JPEND', true);   // fixed but never invoiced -> excluded
    funnelCorrection('JFEB', true);    // fixed, Fee avoided (February — a second bucket)

    DB::table('carton_costs')->insert([
        ['tracking_number' => 'TFIX', 'pace_job_number' => 'JFIX', 'ship_cost' => 5, 'ship_date' => '2026-03-05', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TFIX2', 'pace_job_number' => 'JFIX2', 'ship_cost' => 5, 'ship_date' => '2026-03-06', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TNO', 'pace_job_number' => 'JNOFIX', 'ship_cost' => 5, 'ship_date' => '2026-03-07', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TPEND', 'pace_job_number' => 'JPEND', 'ship_cost' => 5, 'ship_date' => '2026-03-08', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TFEB', 'pace_job_number' => 'JFEB', 'ship_cost' => 5, 'ship_date' => '2026-02-10', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('carrier_charges')->insert([
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TFIX', 'invoice_date' => '2026-04-01', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 4, 'created_at' => now(), 'updated_at' => now()],            // invoiced, no fee -> avoided
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TFIX2', 'invoice_date' => '2026-04-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 12, 'created_at' => now(), 'updated_at' => now()], // fee -> charged_fixed + billed_back
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TNO', 'invoice_date' => '2026-04-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 9, 'created_at' => now(), 'updated_at' => now()],   // fee -> charged_nofix
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TFEB', 'invoice_date' => '2026-03-01', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 3, 'created_at' => now(), 'updated_at' => now()],             // invoiced, no fee -> avoided (Feb)
        // TPEND: no carrier charge -> not invoiced -> excluded entirely.
    ]);

    DB::table('chargeback_pushes')->insert([
        ['dedupe_key' => 'r1', 'tracking_number' => 'TFIX2', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 12, 'status' => 'pushed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs(User::factory()->create());
});

test('the funnel breaks a year out by month, counting invoiced shipments per stage', function () {
    $series = app(CorrectionOutcomeService::class)->funnelSeries(2026, null)->keyBy('label');

    // February bucket: one fixed shipment, fee avoided.
    expect($series['02']->processed)->toBe(1)
        ->and($series['02']->avoided)->toBe(1);

    // March bucket: the three invoiced shipments split across the stages (TPEND excluded).
    $march = $series['03'];
    expect($march->processed)->toBe(3)
        ->and($march->fixed)->toBe(2)
        ->and($march->avoided)->toBe(1)
        ->and($march->charged_fixed)->toBe(1)
        ->and($march->charged_nofix)->toBe(1)
        ->and($march->billed_back)->toBe(1);
});

test('the funnel widget renders the headline totals across the buckets', function () {
    Livewire::test(CorrectionOutcomeChart::class, ['pageFilters' => ['year' => 2026, 'month' => 0]])
        ->assertOk()
        ->assertSee('Address Correction Funnel')
        // Feb (1 processed/fixed/avoided) + March (3/2/1, 2 charged) => 4 / 3 / 2 / 2.
        ->assertSee('4 processed · 3 fixed · 2 fee avoided · 2 still charged');
});
