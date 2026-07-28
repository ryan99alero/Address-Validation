<?php

use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CartonCost;
use App\Models\ChargebackPush;
use App\Models\CorrectedAddress;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $this->invId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->corrected = CorrectedAddress::create([
        'address_1' => '100 GOOD ST', 'city' => 'AUSTIN', 'state' => 'TX', 'postal' => '78701', 'country' => 'us', 'address_hash' => 'good',
        'first_seen_at' => now(),
    ]);
});

function makeVariant(int $correctedId, string $addr1, string $city, string $state, string $postal): AddressVariant
{
    return AddressVariant::create([
        'corrected_address_id' => $correctedId,
        'input_address_1' => $addr1, 'input_city' => $city, 'input_state' => $state, 'input_postal' => $postal,
        'input_hash' => AddressVariant::computeHash($addr1, $city, $state, $postal, 'us'),
        'is_active' => true, 'times_seen' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

function makeCorrectionLine(int $invId, int $correctedId, string $tracking, string $addr1, string $city, string $state, string $postal, string $shipDate): void
{
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $invId, 'corrected_address_id' => $correctedId, 'tracking_number' => $tracking,
        'original_address_1' => $addr1, 'original_city' => $city, 'original_state' => $state, 'original_postal' => $postal, 'original_country' => 'US',
        'ship_date' => $shipDate, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('variantOccurrences links each bad address to its most recent tracking + Pace job/customer/rep data', function () {
    $variant = makeVariant($this->corrected->id, '123 MAIN ST', 'AUSTIN', 'TX', '78701');

    // Two occurrences of this bad address — the newer ship date wins for "most recent tracking".
    makeCorrectionLine($this->invId, $this->corrected->id, 'OLD9', '123 MAIN ST', 'AUSTIN', 'TX', '78701', '2026-01-05');
    makeCorrectionLine($this->invId, $this->corrected->id, '1Z9', '123 MAIN ST', 'AUSTIN', 'TX', '78701', '2026-02-10');

    CartonCost::create(['tracking_number' => '1Z9', 'pace_job_number' => 'JOB1', 'pace_customer_id' => 'CUST1']);
    ChargebackPush::create([
        'dedupe_key' => 'k', 'carrier_id' => $this->carrier->id, 'tracking_number' => '1Z9', 'amount' => 1, 'status' => 'pushed',
        'pace_customer_name' => 'Acme Co', 'pace_csr_name' => 'Jane', 'pace_salesperson_name' => 'Bob',
    ]);

    $map = $this->corrected->variantOccurrences();

    expect($map)->toHaveKey($variant->input_hash);
    $o = $map[$variant->input_hash];
    expect($o['tracking'])->toBe('1Z9')          // most recent, not OLD9
        ->and($o['date'])->toStartWith('2026-02-10') // the newer occurrence's ship date
        ->and($o['job'])->toBe('JOB1')
        ->and($o['customer_id'])->toBe('CUST1')
        ->and($o['customer_name'])->toBe('Acme Co')
        ->and($o['csr'])->toBe('Jane')
        ->and($o['salesperson'])->toBe('Bob');
});

test('latestOccurrence returns the most recent tracking + reference date for a bad address', function () {
    $variant = makeVariant($this->corrected->id, '55 REPEAT AVE', 'AUSTIN', 'TX', '78701');
    makeCorrectionLine($this->invId, $this->corrected->id, 'OLD', '55 REPEAT AVE', 'AUSTIN', 'TX', '78701', '2025-01-01');
    makeCorrectionLine($this->invId, $this->corrected->id, 'NEW', '55 REPEAT AVE', 'AUSTIN', 'TX', '78701', '2026-03-15');

    $occ = $variant->latestOccurrence();
    expect($occ['tracking'])->toBe('NEW')
        ->and($occ['date'])->toStartWith('2026-03-15');
});

test('variantOccurrences prefers the carton rep names over chargeback names', function () {
    $variant = makeVariant($this->corrected->id, '77 CARTON WAY', 'AUSTIN', 'TX', '78701');
    makeCorrectionLine($this->invId, $this->corrected->id, 'C1', '77 CARTON WAY', 'AUSTIN', 'TX', '78701', '2026-02-01');
    CartonCost::create([
        'tracking_number' => 'C1', 'pace_job_number' => 'J', 'pace_customer_id' => 'CID',
        'pace_customer_name' => 'Carton Cust', 'pace_csr_name' => 'Carton CSR', 'pace_salesperson_name' => 'Carton Rep',
    ]);
    ChargebackPush::create([
        'dedupe_key' => 'k2', 'carrier_id' => $this->carrier->id, 'tracking_number' => 'C1', 'amount' => 1, 'status' => 'pushed',
        'pace_customer_name' => 'Push Cust',
    ]);

    $o = $this->corrected->variantOccurrences()[$variant->input_hash];
    expect($o['customer_name'])->toBe('Carton Cust')     // carton wins over the chargeback name
        ->and($o['csr'])->toBe('Carton CSR')
        ->and($o['salesperson'])->toBe('Carton Rep');
});

test('the occurrence date falls back to the invoice date when the line ship_date is null', function () {
    $variant = makeVariant($this->corrected->id, '88 NODATE ST', 'AUSTIN', 'TX', '78701');
    DB::table('carrier_invoice_lines')->insert([
        'carrier_invoice_id' => $this->invId, 'corrected_address_id' => $this->corrected->id, 'tracking_number' => 'ND1',
        'original_address_1' => '88 NODATE ST', 'original_city' => 'AUSTIN', 'original_state' => 'TX', 'original_postal' => '78701', 'original_country' => 'US',
        'ship_date' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $o = $this->corrected->variantOccurrences()[$variant->input_hash];
    expect($o['tracking'])->toBe('ND1')
        ->and($o['date'])->toStartWith('2026-01-01'); // the invoice_date (beforeEach), not the import time
});

test('variantOccurrences returns tracking with null rep data when Pace has nothing', function () {
    $variant = makeVariant($this->corrected->id, '9 UNKNOWN RD', 'AUSTIN', 'TX', '78701');
    makeCorrectionLine($this->invId, $this->corrected->id, 'T5', '9 UNKNOWN RD', 'AUSTIN', 'TX', '78701', '2026-03-01');

    $o = $this->corrected->variantOccurrences()[$variant->input_hash];
    expect($o['tracking'])->toBe('T5')
        ->and($o['job'])->toBeNull()
        ->and($o['customer_name'])->toBeNull();
});
