<?php

use App\Filament\Pages\ChargebackPushes;
use App\Models\ChargebackPush;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChargebackPush::create([
        'dedupe_key' => 'k1', 'carrier_id' => 1, 'tracking_number' => '1ZTEST', 'driver' => 'address_correction',
        'amount' => 20.20, 'activity_code' => '72510', 'pace_job' => 'JOB1', 'pace_jobcost_id' => '555',
        'status' => 'pushed', 'pushed_at' => now(),
    ]);
    $this->actingAs(User::factory()->create());
});

test('the chargeback pushes page lists ledger rows with the JobCost id', function () {
    Livewire::test(ChargebackPushes::class)
        ->assertOk()
        ->assertSee('1ZTEST')
        ->assertSee('72510')
        ->assertSee('555')          // the returned JobCost id
        ->assertTableActionExists('export');
});

test('a flagged duplicate is countable in the nav badge and isolable by filter', function () {
    $canonical = ChargebackPush::create([
        'txn_id' => 'CB1-canon', 'dedupe_key' => 'c1', 'carrier_id' => 1, 'tracking_number' => '1ZDUP',
        'amount' => 31.00, 'activity_code' => '72530', 'status' => 'pushed', 'pace_jobcost_id' => '600',
    ]);
    $dup = ChargebackPush::create([
        'dedupe_key' => 'c2', 'carrier_id' => 1, 'tracking_number' => '1ZDUP', 'amount' => 31.00,
        'activity_code' => '72530', 'status' => 'pushed', 'pace_jobcost_id' => '601',
        'duplicate_of_id' => $canonical->id, 'reversal_state' => ChargebackPush::REVERSAL_NEEDS,
    ]);

    // beforeEach's row is 'pushed' with no reversal flag, so only the duplicate counts toward the badge.
    expect(ChargebackPushes::getNavigationBadge())->toBe('1');

    Livewire::test(ChargebackPushes::class)
        ->filterTable('needs_reversal', true)
        ->assertCanSeeTableRecords([$dup])
        ->assertCanNotSeeTableRecords([$canonical]);
});
