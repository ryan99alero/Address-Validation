<?php

use App\Filament\Resources\AddressSupersessions\Pages\ListAddressSupersessions;
use App\Models\AddressSupersession;
use App\Models\CorrectedAddress;
use App\Models\User;
use Livewire\Livewire;

function reCorrEvent(): AddressSupersession
{
    $a = CorrectedAddress::create([
        'address_1' => '803 s mason rd', 'city' => 'katy', 'state' => 'tx', 'postal' => '77450', 'country' => 'us',
        'address_hash' => CorrectedAddress::computeHash('803 s mason rd', 'katy', 'tx', '77450', 'us'),
        'usage_count' => 1, 'variant_count' => 0, 'first_seen_at' => now(),
    ]);
    $b = CorrectedAddress::create([
        'address_1' => '803 s mason rd ste460', 'city' => 'katy', 'state' => 'tx', 'postal' => '77450', 'country' => 'us',
        'address_hash' => CorrectedAddress::computeHash('803 s mason rd ste460', 'katy', 'tx', '77450', 'us'),
        'usage_count' => 1, 'variant_count' => 0, 'first_seen_at' => now(),
    ]);

    return AddressSupersession::create([
        'old_corrected_address_id' => $a->id, 'new_corrected_address_id' => $b->id,
        'old_snapshot' => ['address_1' => '803 s mason rd', 'address_2' => 'suite: 460', 'city' => 'katy', 'state' => 'tx', 'postal' => '77450'],
        'new_snapshot' => ['address_1' => '803 s mason rd ste460', 'city' => 'katy', 'state' => 'tx', 'postal' => '77450'],
        'trigger' => 'backfill', 'status' => 'pending_review', 'detected_at' => now(), 'reference_date' => '2024-05-25',
    ]);
}

test('re-corrections list renders with the structured address + filters', function () {
    reCorrEvent();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(ListAddressSupersessions::class)
        ->assertOk()
        ->assertSee('803 s mason rd')
        ->assertSee('suite: 460');   // address_2 now visible (was dropped before)
});

test('the details modal saves a corrected override and flags the event manually edited', function () {
    $event = reCorrEvent();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(ListAddressSupersessions::class)
        ->callTableAction('details', $event, data: [
            'company' => 'Yankee Candle 263',
            'name' => 'ATTN: STORE MANAGER',
            'address_1' => '803 s mason rd',
            'address_2' => 'ste 460',
            'city' => 'katy',
            'state' => 'tx',
            'postal' => '77450',
        ]);

    $event->refresh();
    expect($event->isManuallyEdited())->toBeTrue()
        ->and($event->corrected_edited_by)->toBe($admin->id)
        ->and($event->corrected_override['address_2'])->toBe('ste 460');
});
