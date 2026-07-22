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
        ->filterTable('view', ChargebackPush::REVERSAL_NEEDS)
        ->assertCanSeeTableRecords([$dup])
        ->assertCanNotSeeTableRecords([$canonical]);
});

test('a quarantined near-duplicate surfaces in Needs Review and can be dismissed', function () {
    $posted = ChargebackPush::create([
        'txn_id' => 'CB1-p', 'dedupe_key' => 'pp', 'carrier_id' => 1, 'tracking_number' => '1ZQ',
        'amount' => 18.40, 'activity_code' => '72510', 'status' => 'pushed', 'pace_jobcost_id' => '900',
    ]);
    $q = ChargebackPush::create([
        'txn_id' => 'CB1-q', 'dedupe_key' => 'qq', 'carrier_id' => 1, 'tracking_number' => '1ZQ',
        'amount' => 20.20, 'activity_code' => '72510', 'status' => ChargebackPush::STATUS_QUARANTINED,
        'conflict_with_id' => $posted->id, 'conflict_reason' => ChargebackPush::CONFLICT_AMOUNT,
    ]);

    expect(ChargebackPushes::getNavigationBadge())->toBe('1'); // only the quarantined row awaits a human

    Livewire::test(ChargebackPushes::class)
        ->filterTable('view', ChargebackPush::STATUS_QUARANTINED)
        ->assertCanSeeTableRecords([$q])
        ->assertCanNotSeeTableRecords([$posted])
        ->assertTableActionExists('push_anyway')
        ->assertTableActionExists('dismiss')
        ->callTableAction('dismiss', $q, data: ['review_note' => 'both are genuinely owed on this shipment']);

    expect($q->refresh()->status)->toBe(ChargebackPush::STATUS_DISMISSED)
        ->and($q->review_note)->toBe('both are genuinely owed on this shipment')
        ->and($q->reviewed_by_id)->not->toBeNull();
});

test('a flagged duplicate can be marked reversed, clearing it from needs-reversal and the badge', function () {
    $canonical = ChargebackPush::create(['txn_id' => 'CB1-c', 'dedupe_key' => 'c1', 'carrier_id' => 1, 'tracking_number' => '1ZDUP', 'amount' => 31.00, 'activity_code' => '72530', 'status' => 'pushed', 'pace_jobcost_id' => '600']);
    $dup = ChargebackPush::create(['dedupe_key' => 'c2', 'carrier_id' => 1, 'tracking_number' => '1ZDUP', 'amount' => 31.00, 'activity_code' => '72530', 'status' => 'pushed', 'pace_jobcost_id' => '601', 'duplicate_of_id' => $canonical->id, 'reversal_state' => ChargebackPush::REVERSAL_NEEDS]);

    Livewire::test(ChargebackPushes::class)
        ->assertTableActionExists('mark_reversed')
        ->callTableAction('mark_reversed', $dup);

    expect($dup->refresh()->status)->toBe(ChargebackPush::STATUS_REVERSED)
        ->and($dup->reversal_state)->toBeNull()
        ->and($dup->reviewed_by_id)->not->toBeNull()
        ->and(ChargebackPushes::getNavigationBadge())->toBeNull(); // nothing awaits a human now
});

test('the ship-date range filters by when the shipment HAPPENED, not the import/created date', function () {
    // Both rows were "imported" now (created_at = now), but their real ship dates differ.
    $old = ChargebackPush::create(['dedupe_key' => 'old', 'carrier_id' => 1, 'tracking_number' => 'OLD', 'amount' => 5, 'status' => 'pushed', 'ship_date' => '2024-03-01']);
    $recent = ChargebackPush::create(['dedupe_key' => 'new', 'carrier_id' => 1, 'tracking_number' => 'NEW', 'amount' => 5, 'status' => 'pushed', 'ship_date' => '2026-07-01']);

    Livewire::test(ChargebackPushes::class)
        ->filterTable('range_ship_date', ['from' => '2026-01-01'])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});
