<?php

use App\Filament\Widgets\CorrectionOutcomeChart;
use App\Models\Carrier;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Analytics\CorrectionOutcomeService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);
    DB::table('charge_categories')->insert([
        ['id' => 1, 'name' => 'Address Correction', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 13, 'name' => 'Base Transportation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Four corrected jobs (array-shaped changes so the changes-non-empty check works on SQLite).
    foreach (['JWIN', 'JCHG', 'JREC', 'JPEND'] as $job) {
        SystemLog::create([
            'category' => 'integration', 'type' => 'pace_address_correction', 'level' => 'info', 'status' => 'success',
            'summary' => 'x', 'metadata' => ['job_number' => $job, 'changes' => [['field' => 'zip', 'from' => '0', 'to' => '1']]],
        ]);
    }

    DB::table('carton_costs')->insert([
        ['tracking_number' => 'TWIN', 'pace_job_number' => 'JWIN', 'ship_cost' => 5, 'ship_date' => '2026-03-05', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TCHG', 'pace_job_number' => 'JCHG', 'ship_cost' => 5, 'ship_date' => '2026-03-10', 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TREC', 'pace_job_number' => 'JREC', 'ship_cost' => 5, 'ship_date' => '2026-03-15', 'created_at' => now(), 'updated_at' => now()],
        // Shipped but no carrier charge yet => NOT invoiced => must be excluded entirely.
        ['tracking_number' => 'TPEND', 'pace_job_number' => 'JPEND', 'ship_cost' => 5, 'ship_date' => '2026-03-20', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('carrier_invoices')->insert(['id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV1', 'invoice_date' => '2026-04-01', 'created_at' => now(), 'updated_at' => now()]);

    DB::table('carrier_charges')->insert([
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TWIN', 'invoice_date' => '2026-04-01', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 4, 'created_at' => now(), 'updated_at' => now()],           // invoiced, no fee -> prevented
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TCHG', 'invoice_date' => '2026-04-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 12, 'created_at' => now(), 'updated_at' => now()], // fee, not recouped -> charged
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TREC', 'invoice_date' => '2026-04-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 9, 'created_at' => now(), 'updated_at' => now()],  // fee -> recouped (below)
    ]);

    DB::table('chargeback_pushes')->insert([
        ['dedupe_key' => 'r1', 'tracking_number' => 'TREC', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 9, 'status' => 'pushed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs(User::factory()->create());
});

test('outcomeSeries counts only invoiced shipments and splits prevented / recouped / charged', function () {
    $row = app(CorrectionOutcomeService::class)->outcomeSeries(2026, null)->firstWhere('label', '03');

    expect($row)->not->toBeNull()
        ->and($row->prevented)->toBe(1)  // TWIN
        ->and($row->recouped)->toBe(1)   // TREC
        ->and($row->charged)->toBe(1);   // TCHG (TPEND excluded — never invoiced)
});

test('the Correction Outcomes widget renders the prevented headline', function () {
    Livewire::test(CorrectionOutcomeChart::class, ['pageFilters' => ['year' => 2026, 'month' => 0]])
        ->assertOk()
        ->assertSee('Correction Outcomes')
        ->assertSee('3 invoiced · 33% prevented');
});
