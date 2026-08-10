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

    funnelCorrection('JFIX', true);    // fixed, will be Fee avoided
    funnelCorrection('JFIX2', true);   // fixed, will be Charged + Billed back
    funnelCorrection('JNOFIX', false); // no-change, will be Charged (no fix)
    funnelCorrection('JPEND', true);   // fixed but never invoiced -> excluded

    DB::table('carton_costs')->insert([
        ['tracking_number' => 'TFIX', 'pace_job_number' => 'JFIX', 'ship_cost' => 5, 'ship_date' => '2026-03-05', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TFIX2', 'pace_job_number' => 'JFIX2', 'ship_cost' => 5, 'ship_date' => '2026-03-06', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TNO', 'pace_job_number' => 'JNOFIX', 'ship_cost' => 5, 'ship_date' => '2026-03-07', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TPEND', 'pace_job_number' => 'JPEND', 'ship_cost' => 5, 'ship_date' => '2026-03-08', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('carrier_charges')->insert([
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TFIX', 'invoice_date' => '2026-04-01', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 4, 'created_at' => now(), 'updated_at' => now()],            // invoiced, no fee -> avoided
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TFIX2', 'invoice_date' => '2026-04-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 12, 'created_at' => now(), 'updated_at' => now()], // fee -> charged_fixed + billed_back
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TNO', 'invoice_date' => '2026-04-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 9, 'created_at' => now(), 'updated_at' => now()],   // fee -> charged_nofix
        // TPEND: no carrier charge -> not invoiced -> excluded entirely.
    ]);

    DB::table('chargeback_pushes')->insert([
        ['dedupe_key' => 'r1', 'tracking_number' => 'TFIX2', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 12, 'status' => 'pushed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs(User::factory()->create());
});

test('the funnel counts invoiced shipments through each stage', function () {
    $f = app(CorrectionOutcomeService::class)->funnel(2026, null);

    expect($f->processed)->toBe(3)       // TFIX, TFIX2, TNO (TPEND not invoiced)
        ->and($f->fixed)->toBe(2)        // TFIX, TFIX2
        ->and($f->avoided)->toBe(1)      // TFIX
        ->and($f->charged_fixed)->toBe(1) // TFIX2
        ->and($f->charged_nofix)->toBe(1) // TNO
        ->and($f->billed_back)->toBe(1);  // TFIX2
});

test('the funnel widget renders the headline stages', function () {
    Livewire::test(CorrectionOutcomeChart::class, ['pageFilters' => ['year' => 2026, 'month' => 0]])
        ->assertOk()
        ->assertSee('Address Correction Funnel')
        ->assertSee('3 processed · 2 fixed · 1 fee avoided · 2 still charged');
});
