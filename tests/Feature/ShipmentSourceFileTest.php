<?php

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invoke a protected method with the import source-file property primed, mimicking importFile()
 * having set it before the persist step runs.
 */
function persistWithSourceFile(CarrierInvoiceParserService $svc, string $sourceFile, string $method, array $args): void
{
    $ref = new ReflectionClass($svc);
    $prop = $ref->getProperty('importSourceFile');
    $prop->setAccessible(true);
    $prop->setValue($svc, $sourceFile);

    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    $m->invokeArgs($svc, $args);
}

test('a FedEx PDF-sourced shipment records the PDF as its source_file', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    // The invoice's own filename is the CSV (FedEx imports the CSV first) — the shipment must still
    // reference the PDF it came in on.
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id, 'invoice_number' => '9-391-32179',
        'invoice_date' => '2026-07-24', 'filename' => 'FedEx_invoice_2026-07-29_08_38.CSV',
    ]);

    persistWithSourceFile(
        app(CarrierInvoiceParserService::class),
        'FedEx_invoice_2026-07-29_08_38.PDF',
        'persistFedExShipments',
        [$invoice, ['873293257130' => ['zip' => '80112', 'service' => 'FedEx Ground', 'ship_date' => '2026-07-20']], 'pdf'],
    );

    $shipment = CarrierShipment::where('tracking_number', '873293257130')->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->source_type)->toBe('pdf')
        ->and($shipment->source_file)->toBe('FedEx_invoice_2026-07-29_08_38.PDF')  // the PDF, not the CSV
        ->and($invoice->filename)->toBe('FedEx_invoice_2026-07-29_08_38.CSV');     // invoice still shows the CSV
});

test('a FedEx CSV-sourced shipment records the CSV as its source_file', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $invoice = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'X1', 'invoice_date' => '2026-07-24']);

    persistWithSourceFile(
        app(CarrierInvoiceParserService::class),
        'FedEx_invoice_2026-07-29_08_38.CSV',
        'persistFedExShipments',
        [$invoice, ['999888777666' => ['zip' => '10001', 'service' => 'FedEx 2Day']], 'csv'],
    );

    $shipment = CarrierShipment::where('tracking_number', '999888777666')->first();
    expect($shipment->source_type)->toBe('csv')
        ->and($shipment->source_file)->toBe('FedEx_invoice_2026-07-29_08_38.CSV');
});
