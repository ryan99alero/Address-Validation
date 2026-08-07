<?php

use App\Filament\Resources\PaceCorrections\Pages\ListPaceCorrections;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function paceResidentialLog(array $metadata): SystemLog
{
    return SystemLog::create([
        'category' => 'integration',
        'type' => 'pace_address_correction',
        'level' => 'info',
        'status' => 'success',
        'summary' => 'Pace address correction',
        'metadata' => $metadata,
    ]);
}

beforeEach(function () {
    // We pushed residential = true onto the Pace Contact on this correction.
    $this->setResidential = paceResidentialLog([
        'shipment_id' => 'SHIP-SET',
        'residential' => true,
        'changed_fields' => ['residential'],
        'changes' => ['residential' => ['from' => 'false', 'to' => true]],
    ]);
    // Validated residential, but the flag was already right — no residential change pushed.
    $this->verifiedResidential = paceResidentialLog([
        'shipment_id' => 'SHIP-RES',
        'residential' => true,
        'changes' => ['zip' => ['from' => '12345', 'to' => '12345-6789']],
    ]);
    // Commercial address.
    $this->commercial = paceResidentialLog([
        'shipment_id' => 'SHIP-COM',
        'residential' => false,
        'changes' => [],
    ]);
    $this->actingAs(User::factory()->create());
});

test('the Residential column labels set / verified / commercial rows', function () {
    Livewire::test(ListPaceCorrections::class)
        ->assertTableColumnStateSet('residential', 'Set Residential', $this->setResidential)
        ->assertTableColumnStateSet('residential', 'Residential', $this->verifiedResidential)
        ->assertTableColumnStateSet('residential', 'Commercial', $this->commercial);
});

test('the Residential-set filter shows only rows where we pushed the residential flag', function () {
    Livewire::test(ListPaceCorrections::class)
        ->filterTable('residential_set', true)
        ->assertCanSeeTableRecords([$this->setResidential])
        ->assertCanNotSeeTableRecords([$this->verifiedResidential, $this->commercial]);
});
