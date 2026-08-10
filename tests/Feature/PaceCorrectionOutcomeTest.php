<?php

use App\Filament\Resources\PaceCorrections\Pages\ListPaceCorrections;
use App\Models\Carrier;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function outcomeCorrection(string $job): SystemLog
{
    return SystemLog::create([
        'category' => 'integration',
        'type' => 'pace_address_correction',
        'level' => 'info',
        'status' => 'success',
        'summary' => 'Pace address correction',
        'metadata' => ['job_number' => $job, 'changes' => [['field' => 'zip', 'from' => '0', 'to' => '1']]],
    ]);
}

beforeEach(function () {
    Carrier::factory()->create(['id' => 1, 'slug' => 'ups']);
    DB::table('charge_categories')->insert([
        ['id' => 1, 'name' => 'Address Correction', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 13, 'name' => 'Base Transportation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('carrier_invoices')->insert(['id' => 1, 'carrier_id' => 1, 'invoice_number' => 'INV1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);

    $this->win = outcomeCorrection('JWIN');      // shipped, no address/residential fee
    $this->charged = outcomeCorrection('JCHG');  // shipped, address-correction fee
    $this->pending = outcomeCorrection('JPEND'); // never shipped (no carton)

    DB::table('carton_costs')->insert([
        ['tracking_number' => 'TWIN', 'pace_job_number' => 'JWIN', 'ship_cost' => 10, 'created_at' => now(), 'updated_at' => now()],
        ['tracking_number' => 'TCHG', 'pace_job_number' => 'JCHG', 'ship_cost' => 10, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('carrier_charges')->insert([
        // TWIN: only base transport — no address/residential fee => Prevented.
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TWIN', 'invoice_date' => '2026-01-01', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 8, 'created_at' => now(), 'updated_at' => now()],
        // TCHG: an address-correction fee => Charged.
        ['carrier_id' => 1, 'carrier_invoice_id' => 1, 'tracking_number' => 'TCHG', 'invoice_date' => '2026-01-01', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 12.5, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs(User::factory()->create());
});

test('the Fee Outcome column reports prevented / charged / pending', function () {
    Livewire::test(ListPaceCorrections::class)
        ->assertTableColumnStateSet('fee_outcome', 'Prevented', $this->win)
        ->assertTableColumnStateSet('fee_outcome', 'Charged · $12.50', $this->charged)
        ->assertTableColumnStateSet('fee_outcome', 'Pending', $this->pending);
});

test('the Fee-outcome filter isolates the prevented (win) cohort', function () {
    Livewire::test(ListPaceCorrections::class)
        ->filterTable('outcome', 'prevented')
        ->assertCanSeeTableRecords([$this->win])
        ->assertCanNotSeeTableRecords([$this->charged, $this->pending]);
});

test('the Fee-outcome filter isolates charged and pending too', function () {
    Livewire::test(ListPaceCorrections::class)
        ->filterTable('outcome', 'charged')
        ->assertCanSeeTableRecords([$this->charged])
        ->assertCanNotSeeTableRecords([$this->win, $this->pending]);

    Livewire::test(ListPaceCorrections::class)
        ->filterTable('outcome', 'pending')
        ->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->win, $this->charged]);
});
