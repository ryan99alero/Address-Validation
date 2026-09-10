<?php

use App\Models\AddressSupersession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cleanup dismisses fee-free reformats, keeps real changes, and repairs bogus dates', function () {
    $trivial = AddressSupersession::create([
        'trigger' => AddressSupersession::TRIGGER_REVERIFY_DRIFT,
        'status' => AddressSupersession::STATUS_PENDING_REVIEW,
        'old_snapshot' => ['address_1' => '405 babcock blvd w ste110', 'city' => 'phoenix', 'state' => 'AZ', 'postal' => '85001'],
        'new_snapshot' => ['address_1' => '405 babcock blvd w', 'city' => 'phoenix', 'state' => 'AZ', 'postal' => '85001'],
        'detected_at' => now(),
    ]);
    $real = AddressSupersession::create([
        'trigger' => AddressSupersession::TRIGGER_REVERIFY_DRIFT,
        'status' => AddressSupersession::STATUS_PENDING_REVIEW,
        'old_snapshot' => ['address_1' => '1000 e pancake blvd', 'city' => 'liberal', 'state' => 'KS', 'postal' => '67901'],
        'new_snapshot' => ['address_1' => '1000 w pancake blvd', 'city' => 'liberal', 'state' => 'KS', 'postal' => '67901'],
        'detected_at' => now(),
    ]);
    $bogus = AddressSupersession::create([
        'trigger' => AddressSupersession::TRIGGER_BACKFILL,
        'status' => AddressSupersession::STATUS_DISMISSED,
        'reference_date' => '0020-11-07',
        'detected_at' => now(),
    ]);

    $this->artisan('recorrections:cleanup')->assertSuccessful();

    expect($trivial->fresh()->status)->toBe(AddressSupersession::STATUS_DISMISSED)
        ->and($real->fresh()->status)->toBe(AddressSupersession::STATUS_PENDING_REVIEW)
        ->and($bogus->fresh()->reference_date->toDateString())->toBe('2020-11-07');
});

test('dry-run reports but writes nothing', function () {
    $trivial = AddressSupersession::create([
        'trigger' => AddressSupersession::TRIGGER_REVERIFY_DRIFT,
        'status' => AddressSupersession::STATUS_PENDING_REVIEW,
        'old_snapshot' => ['address_1' => '511 n central expy ste210', 'city' => 'plano', 'state' => 'TX', 'postal' => '75074'],
        'new_snapshot' => ['address_1' => '511 n central expy', 'city' => 'plano', 'state' => 'TX', 'postal' => '75074'],
        'detected_at' => now(),
    ]);

    $this->artisan('recorrections:cleanup --dry-run')->assertSuccessful();

    expect($trivial->fresh()->status)->toBe(AddressSupersession::STATUS_PENDING_REVIEW);
});
