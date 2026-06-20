<?php

use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;

test('UPS PDF corrections build invoice lines and populate the correction cache', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id,
        'filename' => 'Invoice_test.PDF',
        'file_hash' => hash('sha256', 'test-invoice'),
        'status' => 'pending',
    ]);

    $corrections = [
        [
            'tracking_number' => '1Z6913170390656918',
            'recorded' => ['name' => 'PIZZA HUT 39499', 'address_1' => '9001 FM 1472', 'address_2' => null, 'city' => 'LAREDO', 'state' => 'TX', 'postal' => '78041'],
            'corrected' => ['name' => 'PIZZA HUT 39499', 'address_1' => '9001 MINES RD', 'address_2' => null, 'city' => 'LAREDO', 'state' => 'TX', 'postal' => '78045'],
        ],
        [
            // No usable corrected address — must be skipped.
            'tracking_number' => '1Z0000000000000000',
            'recorded' => ['name' => null, 'address_1' => '1 NOWHERE', 'address_2' => null, 'city' => 'X', 'state' => 'TX', 'postal' => '70000'],
            'corrected' => ['name' => null, 'address_1' => null, 'address_2' => null, 'city' => null, 'state' => null, 'postal' => null],
        ],
    ];

    $service = app(CarrierInvoiceParserService::class);
    $built = $service->buildCorrectionLines($invoice, $corrections);

    expect($built)->toBe(1); // second correction skipped (no corrected address)
    expect($invoice->correctionLines()->count())->toBe(1);

    // Push the line into the address correction cache.
    foreach ($invoice->correctionLines()->get() as $line) {
        $line->linkToCorrectionCache();
    }

    // Looking up the recorded (bad) address should now return the corrected one.
    $hit = AddressVariant::lookup('9001 FM 1472', 'LAREDO', 'TX', '78041', 'US');

    expect($hit)->not->toBeNull();
    expect($hit->postal)->toBe('78045');
});
