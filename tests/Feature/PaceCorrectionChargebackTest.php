<?php

use App\Filament\Resources\PaceCorrections\Pages\ListPaceCorrections;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function paceCorrectionForJob(string $job): SystemLog
{
    return SystemLog::create([
        'category' => 'integration',
        'type' => 'pace_address_correction',
        'level' => 'info',
        'status' => 'success',
        'summary' => 'Pace address correction',
        // Array-shaped changes keep the row visible under the default "hide unchanged" filter.
        'metadata' => [
            'job_number' => $job,
            'changes' => [['field' => 'zip', 'from' => '00000', 'to' => '00000-1234']],
        ],
    ]);
}

beforeEach(function () {
    foreach ([[1, 'Address Correction'], [13, 'Base Transportation'], [14, 'Residential Surcharge']] as [$id, $name]) {
        DB::table('charge_categories')->insert(['id' => $id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->withCB = paceCorrectionForJob('M100');
    $this->onlySkipped = paceCorrectionForJob('M300');
    $this->withoutCB = paceCorrectionForJob('M200');

    DB::table('chargeback_pushes')->insert([
        // M100: an address-correction fee actually billed, plus a residential one that was skipped.
        ['dedupe_key' => 'cb1', 'pace_job' => 'M100', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 20, 'status' => 'pushed', 'created_at' => now(), 'updated_at' => now()],
        ['dedupe_key' => 'cb2', 'pace_job' => 'M100', 'charge_category_id' => 14, 'driver' => null, 'amount' => 10, 'status' => 'skipped_job_closed', 'created_at' => now(), 'updated_at' => now()],
        // Base transportation on M100 — must NOT count as an address/residential chargeback.
        ['dedupe_key' => 'cb3', 'pace_job' => 'M100', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 99, 'status' => 'pushed', 'created_at' => now(), 'updated_at' => now()],
        // M300: an address-correction fee that was skipped (job closed) — identified, not billed.
        ['dedupe_key' => 'cb5', 'pace_job' => 'M300', 'charge_category_id' => 1, 'driver' => 'address_correction', 'amount' => 7, 'status' => 'skipped_job_closed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs(User::factory()->create());
});

test('the Client Chargebacks column reports billed vs not-billed vs none', function () {
    Livewire::test(ListPaceCorrections::class)
        ->assertTableColumnStateSet('chargebacks', '1 billed · $20.00', $this->withCB)
        ->assertTableColumnStateSet('chargebacks', '1 not billed', $this->onlySkipped)
        ->assertTableColumnStateSet('chargebacks', '—', $this->withoutCB);
});

test('the Has-chargeback filter shows only corrections whose job was charged back', function () {
    Livewire::test(ListPaceCorrections::class)
        ->filterTable('has_chargeback', true)
        ->assertCanSeeTableRecords([$this->withCB, $this->onlySkipped])
        ->assertCanNotSeeTableRecords([$this->withoutCB]);
});
