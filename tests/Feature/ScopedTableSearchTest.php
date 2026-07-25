<?php

use App\Filament\Pages\ChargebackPushes;
use App\Models\ChargebackPush;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    // Row A: the term is in the TRACKING. Row B: the same term is in the JOB (a different field).
    $this->a = ChargebackPush::create(['dedupe_key' => 'a', 'carrier_id' => 1, 'tracking_number' => '1ZEBRA9', 'amount' => 5, 'status' => 'pushed', 'pace_job' => 'JOB1']);
    $this->b = ChargebackPush::create(['dedupe_key' => 'b', 'carrier_id' => 1, 'tracking_number' => '1XYZ', 'amount' => 5, 'status' => 'pushed', 'pace_job' => 'ZEBRA-JOB']);
});

test('default search (no scope) matches across all fields', function () {
    Livewire::test(ChargebackPushes::class)
        ->searchTable('ZEBRA')
        ->assertCanSeeTableRecords([$this->a, $this->b]); // tracking on A, job on B
});

test('scoping the search to a column restricts it to that field only', function () {
    Livewire::test(ChargebackPushes::class)
        ->set('tableSearchColumn', 'tracking_number')
        ->searchTable('ZEBRA')
        ->assertCanSeeTableRecords([$this->a])       // tracking match
        ->assertCanNotSeeTableRecords([$this->b]);   // job match is excluded when scoped to tracking
});

test('a blank/invalid scope falls back to all-fields search', function () {
    Livewire::test(ChargebackPushes::class)
        ->set('tableSearchColumn', 'not_a_real_column')
        ->searchTable('ZEBRA')
        ->assertCanSeeTableRecords([$this->a, $this->b]); // invalid scope → default behavior
});

test('the scope options are all-fields plus each searchable column', function () {
    $options = Livewire::test(ChargebackPushes::class)->instance()->getSearchScopeOptions();

    expect($options[''])->toBe('All fields')
        ->and($options)->toHaveKey('tracking_number')
        ->and($options)->toHaveKey('pace_job');
});
