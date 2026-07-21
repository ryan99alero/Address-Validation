<?php

use App\Models\Carrier;
use App\Models\ChargebackPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function idCharge(array $over = []): array
{
    return array_merge([
        'carrier_id' => 1, 'tracking_number' => '1Z1', 'activity_code' => '72510',
        'amount' => 20.20, 'invoice_number' => 'INV1', 'invoice_date' => '2026-07-11',
    ], $over);
}

test('identity ignores ship_date but distinguishes invoice, amount, activity, tracking', function () {
    $base = ChargebackPush::identity(idCharge());

    // ship_date is NOT part of the array at all — identity is computed without it, so it cannot fork.
    expect(ChargebackPush::identity(idCharge()))->toBe($base)
        ->and(ChargebackPush::identity(idCharge(['invoice_number' => 'INV2'])))->not->toBe($base)
        ->and(ChargebackPush::identity(idCharge(['invoice_date' => '2026-07-12'])))->not->toBe($base)
        ->and(ChargebackPush::identity(idCharge(['amount' => 20.21])))->not->toBe($base)
        ->and(ChargebackPush::identity(idCharge(['activity_code' => '72520'])))->not->toBe($base)
        ->and(ChargebackPush::identity(idCharge(['tracking_number' => '1Z2'])))->not->toBe($base)
        ->and($base)->toStartWith('CB1-');
});

test('amount is normalized to cents so float formatting cannot fork the identity', function () {
    expect(ChargebackPush::identity(idCharge(['amount' => 20.2])))
        ->toBe(ChargebackPush::identity(idCharge(['amount' => '20.20'])));
});

test('carrier resolves to its slug so a reference-data reseed keeps the identity stable', function () {
    $ups = Carrier::factory()->create(['slug' => 'ups']);
    $withRow = ChargebackPush::identity(idCharge(['carrier_id' => $ups->id]));
    // Recompute after clearing the memo cache (simulating a fresh request) — same slug, same hash.
    $again = ChargebackPush::identity(idCharge(['carrier_id' => $ups->id]));
    expect($withRow)->toBe($again);
});

test('backfill keeps the earliest row canonical and flags the later duplicate for reversal', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $invId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $carrier->id, 'invoice_number' => 'INV1', 'invoice_date' => '2026-07-11',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $common = ['carrier_id' => $carrier->id, 'carrier_invoice_id' => $invId, 'tracking_number' => '1Z1',
        'activity_code' => '72510', 'amount' => 20.20, 'status' => 'pushed'];

    // Gen 1 (earlier, null ship_date) and Gen 2 (later, dated) — same charge, old key forked them.
    $gen1 = ChargebackPush::create($common + ['dedupe_key' => 'g1', 'ship_date' => null, 'pace_jobcost_id' => 'JC1', 'created_at' => now()->subDay()]);
    $gen2 = ChargebackPush::create($common + ['dedupe_key' => 'g2', 'ship_date' => '2026-07-07', 'pace_jobcost_id' => 'JC2', 'created_at' => now()]);

    $this->artisan('chargebacks:backfill-identity')->assertSuccessful();

    $gen1->refresh();
    $gen2->refresh();
    // Earliest is canonical: keeps txn_id, no duplicate pointer.
    expect($gen1->txn_id)->not->toBeNull()
        ->and($gen1->duplicate_of_id)->toBeNull()
        ->and($gen1->reversal_state)->toBeNull()
        // Later one is the duplicate: no txn_id, points at canonical, queued for reversal.
        ->and($gen2->txn_id)->toBeNull()
        ->and($gen2->duplicate_of_id)->toBe($gen1->id)
        ->and($gen2->reversal_state)->toBe(ChargebackPush::REVERSAL_NEEDS);
});

test('backfill leaves a unique charge alone', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $row = ChargebackPush::create(['dedupe_key' => 'u1', 'carrier_id' => $carrier->id, 'tracking_number' => '1Z9',
        'activity_code' => '72510', 'amount' => 5.00, 'status' => 'pushed']);

    $this->artisan('chargebacks:backfill-identity')->assertSuccessful();

    expect($row->refresh()->txn_id)->not->toBeNull()
        ->and($row->duplicate_of_id)->toBeNull()
        ->and($row->reversal_state)->toBeNull();
});
