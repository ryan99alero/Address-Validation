<?php

use App\Console\Commands\BackfillFedExServiceType;
use App\Models\Carrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(),
        'charges_reconciled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function makeShipment(array $attrs): void
{
    DB::table('carrier_shipments')->insert(array_merge([
        'carrier_id' => test()->carrier->id,
        'carrier_invoice_id' => test()->invoiceId,
        'source_type' => 'pdf',
        'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function serviceOf(string $tracking): ?string
{
    return DB::table('carrier_shipments')->where('tracking_number', $tracking)->value('service');
}

test('backfills payment-term and null services, leaves a clean service untouched', function () {
    makeShipment(['tracking_number' => '111111111111', 'service' => 'Ppd, Domestic']); // Ground mislabel
    makeShipment(['tracking_number' => '222222222222', 'service' => null]);            // Express null
    makeShipment(['tracking_number' => '333333333333', 'service' => 'FedEx Ground']);  // already clean

    $rows = [
        ['tracking' => '111111111111', 'service' => 'FedEx Ground'],
        ['tracking' => '222222222222', 'service' => 'FedEx 2Day'],
        ['tracking' => '333333333333', 'service' => 'FedEx Ground'],
    ];

    $result = app(BackfillFedExServiceType::class)->applyShipmentServices($this->carrier->id, $rows, false);

    expect($result['updated'])->toBe(2)
        ->and(serviceOf('111111111111'))->toBe('FedEx Ground')
        ->and(serviceOf('222222222222'))->toBe('FedEx 2Day')
        ->and(serviceOf('333333333333'))->toBe('FedEx Ground');
});

test('counts still-unresolved services (payment term / none) as lookup failures without writing', function () {
    makeShipment(['tracking_number' => '444444444444', 'service' => 'Ppd, Domestic']);

    $rows = [
        ['tracking' => '444444444444', 'service' => 'Collect, Domestic'], // parser still couldn't name it
        ['tracking' => '555555555555', 'service' => null],
    ];

    $result = app(BackfillFedExServiceType::class)->applyShipmentServices($this->carrier->id, $rows, false);

    expect($result['unresolved'])->toBe(2)
        ->and($result['updated'])->toBe(0)
        ->and($result['unresolved_samples'])->toHaveCount(2)
        ->and(serviceOf('444444444444'))->toBe('Ppd, Domestic');
});

test('flags parsed trackings that have no shipment row', function () {
    $rows = [['tracking' => '999999999999', 'service' => 'FedEx 2Day']];

    $result = app(BackfillFedExServiceType::class)->applyShipmentServices($this->carrier->id, $rows, false);

    expect($result['updated'])->toBe(0)->and($result['missing_rows'])->toBe(1);
});

test('dry run counts what would change but writes nothing', function () {
    makeShipment(['tracking_number' => '111111111111', 'service' => 'Ppd, Domestic']);

    $rows = [['tracking' => '111111111111', 'service' => 'FedEx Ground']];

    $result = app(BackfillFedExServiceType::class)->applyShipmentServices($this->carrier->id, $rows, true);

    expect($result['updated'])->toBe(1)->and(serviceOf('111111111111'))->toBe('Ppd, Domestic');
});
