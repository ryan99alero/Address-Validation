<?php

use App\Filament\Resources\AddressSupersessions\Pages\ListAddressSupersessions;
use App\Models\AddressSupersession;
use App\Models\CorrectedAddress;
use App\Models\User;
use App\Services\Invoices\CorrectionThreader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function loopGood(string $addr1, string $postal): CorrectedAddress
{
    return CorrectedAddress::create([
        'address_1' => $addr1, 'city' => 'austin', 'state' => 'tx', 'postal' => $postal, 'country' => 'us',
        'address_hash' => CorrectedAddress::computeHash($addr1, 'austin', 'tx', $postal, 'us'),
        'usage_count' => 1, 'variant_count' => 0, 'first_seen_at' => now(),
    ]);
}

beforeEach(function () {
    $threader = app(CorrectionThreader::class);
    $a = loopGood('1 a st', '78701');
    $b = loopGood('2 b st', '78702');
    $c = loopGood('3 c st', '78703');
    $d = loopGood('4 d st', '78704');

    // A -> B and its mirror B -> A form a reversal loop; C -> D does not.
    $this->ab = $threader->recordEvent($a, $b, AddressSupersession::TRIGGER_RECORRECTION, AddressSupersession::STATUS_PENDING_REVIEW);
    $this->ba = $threader->recordEvent($b, $a, AddressSupersession::TRIGGER_RECORRECTION, AddressSupersession::STATUS_PENDING_REVIEW);
    $this->cd = $threader->recordEvent($c, $d, AddressSupersession::TRIGGER_RECORRECTION, AddressSupersession::STATUS_PENDING_REVIEW);

    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

test('the Loop column flags reversal events and leaves one-way corrections blank', function () {
    Livewire::test(ListAddressSupersessions::class)
        ->assertTableColumnStateSet('loop', '↔ Reversal', $this->ab)
        ->assertTableColumnStateSet('loop', '↔ Reversal', $this->ba)
        ->assertTableColumnStateSet('loop', null, $this->cd);
});

test('the Reversal-loops filter shows only the A<->B thrash events', function () {
    Livewire::test(ListAddressSupersessions::class)
        ->filterTable('reversal', true)
        ->assertCanSeeTableRecords([$this->ab, $this->ba])
        ->assertCanNotSeeTableRecords([$this->cd]);
});
