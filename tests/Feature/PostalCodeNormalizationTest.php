<?php

use App\Models\Address;
use App\Support\PostalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('pads Excel-truncated US zips and leaves everything else alone', function () {
    expect(PostalCode::normalizeUs('6514', 'US'))->toBe('06514')          // 4 → 5
        ->and(PostalCode::normalizeUs('501', 'US'))->toBe('00501')        // 3 → 5
        ->and(PostalCode::normalizeUs('7001-1234', 'US'))->toBe('07001-1234') // ZIP+4 preserved
        ->and(PostalCode::normalizeUs('1234', ''))->toBe('01234')         // blank country = US
        ->and(PostalCode::normalizeUs('06514', 'US'))->toBe('06514')      // already correct
        ->and(PostalCode::normalizeUs('12345', 'US'))->toBe('12345')      // full 5 untouched
        ->and(PostalCode::normalizeUs('1234', 'CA'))->toBe('1234')        // foreign untouched
        ->and(PostalCode::normalizeUs('K1A 0B1', 'US'))->toBe('K1A 0B1')  // non-numeric untouched
        ->and(PostalCode::normalizeUs(null, 'US'))->toBeNull();
});

it('repairs the ship-to zip on save (all entry points)', function () {
    $address = Address::factory()->create(['input_postal' => '6514', 'input_country' => 'US', 'input_state' => 'CT']);
    expect($address->fresh()->input_postal)->toBe('06514');

    $foreign = Address::factory()->create(['input_postal' => '1234', 'input_country' => 'CA']);
    expect($foreign->fresh()->input_postal)->toBe('1234');
});

it('backfills existing damaged zips, and dry-run changes nothing', function () {
    $address = Address::factory()->create(['input_postal' => '12345', 'input_country' => 'US', 'input_state' => 'CT']);
    DB::table('addresses')->where('id', $address->id)->update(['input_postal' => '6514']); // seed damage, bypass hook

    $this->artisan('zips:normalize-us', ['--dry-run' => true])->assertOk();
    expect($address->fresh()->input_postal)->toBe('6514'); // untouched by dry-run

    $this->artisan('zips:normalize-us')->assertOk();
    expect($address->fresh()->input_postal)->toBe('06514'); // repaired
});
