<?php

use App\Filament\Resources\PaceCorrections\Pages\ListPaceCorrections;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function paceCorrectionLog(string $source, array $changes, string $shipment): SystemLog
{
    return SystemLog::create([
        'category' => 'integration',
        'type' => 'pace_address_correction',
        'level' => 'info',
        'status' => 'success',
        'summary' => 'Pace address correction',
        'metadata' => [
            'source' => $source,
            'shipment_id' => $shipment,
            'changes' => $changes,
        ],
    ]);
}

beforeEach(function () {
    $this->fedexChanged = paceCorrectionLog('fedex_api', [['field' => 'zip', 'from' => '67460', 'to' => '67460-8139']], 'SHIP-FEDEX');
    $this->fedexNoChange = paceCorrectionLog('fedex_api', [], 'SHIP-NOCHANGE');
    $this->cacheChanged = paceCorrectionLog('local_cache', [['field' => 'state', 'from' => 'ks', 'to' => 'mo']], 'SHIP-CACHE');
    $this->actingAs(User::factory()->create());
});

test('the No Changes filter shows only the no-change rows, not FedEx corrections', function () {
    Livewire::test(ListPaceCorrections::class)
        // No-change rows are hidden by default now — opt in to see them.
        ->set('tableFilters.hide_unchanged.isActive', false)
        ->filterTable('source', 'no_changes')
        ->assertCanSeeTableRecords([$this->fedexNoChange])
        ->assertCanNotSeeTableRecords([$this->fedexChanged, $this->cacheChanged]);
});

test('the FedEx filter excludes no-change rows (they belong to No Changes now)', function () {
    Livewire::test(ListPaceCorrections::class)
        ->filterTable('source', 'fedex_api')
        ->assertCanSeeTableRecords([$this->fedexChanged])
        ->assertCanNotSeeTableRecords([$this->fedexNoChange, $this->cacheChanged]);
});

test('the Local Cache filter shows only cache corrections', function () {
    Livewire::test(ListPaceCorrections::class)
        ->filterTable('source', 'local_cache')
        ->assertCanSeeTableRecords([$this->cacheChanged])
        ->assertCanNotSeeTableRecords([$this->fedexChanged, $this->fedexNoChange]);
});

test('the Validator column labels a no-change FedEx row as No Changes', function () {
    Livewire::test(ListPaceCorrections::class)
        // Reveal the no-change row (hidden by default) so its column state can be asserted.
        ->set('tableFilters.hide_unchanged.isActive', false)
        ->assertTableColumnStateSet('source', 'No Changes', $this->fedexNoChange)
        ->assertTableColumnStateSet('source', 'FedEx', $this->fedexChanged)
        ->assertTableColumnStateSet('source', 'Local Cache', $this->cacheChanged);
});

test('the table hides no-change rows by default, and the toggle reveals them', function () {
    Livewire::test(ListPaceCorrections::class)
        ->assertCanSeeTableRecords([$this->fedexChanged, $this->cacheChanged])
        ->assertCanNotSeeTableRecords([$this->fedexNoChange])
        // Toggling the filter off brings the already-clean rows back.
        ->set('tableFilters.hide_unchanged.isActive', false)
        ->assertCanSeeTableRecords([$this->fedexChanged, $this->cacheChanged, $this->fedexNoChange]);
});
