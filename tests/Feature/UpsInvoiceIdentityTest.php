<?php

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;

// upsRow() and writeUpsCsv() are shared helpers defined in tests/Pest.php.

test('same UPS invoice number with different dates stays separate (recycled number)', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $service = app(CarrierInvoiceParserService::class);

    // Same recycled number "E540W079" — 2009 vs 2019 — must not merge.
    $path = writeUpsCsv([
        upsRow('0000000E540W079', '2009-02-14', '1Z0E540W0000000001', '5.82', '2009-02-01'),
        upsRow('0000000E540W079', '2019-02-16', '1Z6913170000000002', '8.97', '2019-02-01'),
    ]);

    $service->importUpsCsv($carrier->id, $path);
    @unlink($path);

    $invoices = CarrierInvoice::where('carrier_id', $carrier->id)->where('invoice_number', 'E540W079')->get();

    expect($invoices)->toHaveCount(2);
    expect($invoices->pluck('invoice_date')->map->format('Y-m-d')->sort()->values()->all())
        ->toBe(['2009-02-14', '2019-02-16']);
    expect($invoices->sum(fn ($i) => $i->charges()->count()))->toBe(2);
});

test('imported charges are stamped with their source format', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $service = app(CarrierInvoiceParserService::class);

    $path = writeUpsCsv([upsRow('000000691317344', '2024-08-24', '1Z6913170394945492', '8.97', '2024-08-14')]);
    $service->importUpsCsv($carrier->id, $path);
    @unlink($path);

    $charge = CarrierInvoice::where('carrier_id', $carrier->id)->where('invoice_number', '691317344')
        ->sole()->charges()->first();

    expect($charge->source_type)->toBe('csv');
});

test('re-importing dedups existing charges, but a recycled tracking on a new ship date is kept', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $service = app(CarrierInvoiceParserService::class);

    $invNum = '000000691317344';
    $invDate = '2024-08-24';
    $tracking = '1Z6913170394945492';

    // First import: one charge.
    $first = writeUpsCsv([upsRow($invNum, $invDate, $tracking, '8.97', '2024-08-14')]);
    $service->importUpsCsv($carrier->id, $first);
    @unlink($first);

    $invoice = CarrierInvoice::where('carrier_id', $carrier->id)->where('invoice_number', '691317344')->sole();
    expect($invoice->charges()->count())->toBe(1);

    // Re-import the identical charge -> deduped against the stored one (still 1).
    $again = writeUpsCsv([upsRow($invNum, $invDate, $tracking, '8.97', '2024-08-14')]);
    $service->importUpsCsv($carrier->id, $again);
    @unlink($again);
    expect($invoice->charges()->count())->toBe(1);

    // Same tracking + amount but a different ship date -> a distinct charge, because
    // ship_date is part of the dedup key (recycled-tracking guard).
    $recycled = writeUpsCsv([upsRow($invNum, $invDate, $tracking, '8.97', '2024-08-20')]);
    $service->importUpsCsv($carrier->id, $recycled);
    @unlink($recycled);
    expect($invoice->charges()->count())->toBe(2);
});
