<?php

use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use Illuminate\Support\Facades\DB;

function makeGood(string $postal, int $usage): CorrectedAddress
{
    return CorrectedAddress::create([
        'address_1' => '14431 culver dr', 'city' => 'irvine', 'state' => 'ca', 'postal' => $postal, 'country' => 'us',
        'address_hash' => CorrectedAddress::computeHash('14431 culver dr', 'irvine', 'ca', $postal, 'us'),
        'usage_count' => $usage, 'variant_count' => 0, 'first_seen_at' => now(),
    ]);
}

function lineFor(int $invId, int $correctedId, string $tracking): void
{
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $invId, 'corrected_address_id' => $correctedId, 'tracking_number' => $tracking,
        'original_address_1' => '14431 CULVER DRIVE', 'original_city' => 'IRVINE', 'original_state' => 'CA',
        'original_postal' => '92714', 'original_country' => 'US', 'ship_date' => '2012-01-01',
        'charge_code' => 'ADC', 'charge_amount' => 11, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('merge-good consolidates the duplicate ZIP records into one canonical 92604-0305', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $invId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $carrier->id, 'invoice_number' => 'INV1', 'invoice_date' => '2012-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r92614 = makeGood('92614', 4);
    $r92604 = makeGood('92604', 26);
    $r92612 = makeGood('92612', 2);

    // The single global variant for the bad address lives under the 92614 record (first-write-wins).
    $variant = AddressVariant::create([
        'corrected_address_id' => $r92614->id,
        'input_address_1' => '14431 culver dr', 'input_city' => 'irvine', 'input_state' => 'ca', 'input_postal' => '92714',
        'input_hash' => AddressVariant::computeHash('14431 culver dr', 'irvine', 'ca', '92714', 'us'),
        'is_active' => true, 'times_seen' => 32, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $r92614->update(['variant_count' => 1]);

    lineFor($invId, $r92614->id, 'T614a');
    lineFor($invId, $r92604->id, 'T604a');
    lineFor($invId, $r92604->id, 'T604b');
    lineFor($invId, $r92612->id, 'T612a');

    $this->artisan('correction-cache:merge-good', [
        '--address1' => '14431 CULVER DR', '--city' => 'Irvine', '--state' => 'CA',
        '--postal' => '92604', '--ext' => '0305',
    ])->assertSuccessful();

    // Canonical is the record already on 92604; the other two are gone.
    expect(CorrectedAddress::find($r92614->id))->toBeNull()
        ->and(CorrectedAddress::find($r92612->id))->toBeNull();

    $canonical = CorrectedAddress::find($r92604->id);
    expect($canonical)->not->toBeNull()
        ->and($canonical->postal)->toBe('92604')
        ->and($canonical->postal_ext)->toBe('0305')
        ->and($canonical->full_address)->toContain('92604-0305')
        ->and($canonical->usage_count)->toBe(32)      // 26 + 4 + 2
        ->and($canonical->variant_count)->toBe(1);

    // Every invoice line now points at the canonical record.
    expect(DB::table('carrier_invoice_lines')->where('corrected_address_id', $canonical->id)->count())->toBe(4)
        ->and(DB::table('carrier_invoice_lines')->whereIn('corrected_address_id', [$r92614->id, $r92612->id])->count())->toBe(0);

    // The variant moved to the canonical record and kept its counter.
    expect($variant->fresh()->corrected_address_id)->toBe($canonical->id)
        ->and($variant->fresh()->times_seen)->toBe(32);
});

test('merge-good is idempotent — a second run makes no further changes', function () {
    makeGood('92604', 5)->update(['postal_ext' => '0305']);

    $this->artisan('correction-cache:merge-good', [
        '--address1' => '14431 CULVER DR', '--city' => 'Irvine', '--state' => 'CA',
        '--postal' => '92604', '--ext' => '0305',
    ])->expectsOutputToContain('Nothing to do')->assertSuccessful();

    expect(CorrectedAddress::where('address_1', '14431 culver dr')->count())->toBe(1);
});
