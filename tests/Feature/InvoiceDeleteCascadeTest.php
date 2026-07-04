<?php

use App\Models\Carrier;
use App\Models\CarrierImportFile;
use App\Models\CarrierInvoice;

test('deleting an invoice clears the source file hash so re-import is not blocked', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id, 'invoice_number' => '691317266',
        'invoice_date' => '2026-06-27', 'status' => 'completed',
    ]);
    $file = CarrierImportFile::create([
        'carrier_id' => $carrier->id, 'file_hash' => str_repeat('a', 64),
        'filename' => 'x.pdf', 'invoice_count' => 1, 'imported_at' => now(),
    ]);
    $file->invoices()->attach($invoice->id);

    $invoice->delete();

    // The now-orphaned import file (and its hash) is gone → a re-scan re-imports the file.
    expect(CarrierImportFile::whereKey($file->id)->exists())->toBeFalse();
});

test('a shared batch file survives until its last invoice is deleted', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex']);

    $a = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'A', 'invoice_date' => '2026-01-01', 'status' => 'completed']);
    $b = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'B', 'invoice_date' => '2026-01-01', 'status' => 'completed']);
    $file = CarrierImportFile::create([
        'carrier_id' => $carrier->id, 'file_hash' => str_repeat('b', 64),
        'filename' => 'batch.csv', 'invoice_count' => 2, 'imported_at' => now(),
    ]);
    $file->invoices()->attach([$a->id, $b->id]);

    $a->delete();
    expect(CarrierImportFile::whereKey($file->id)->exists())->toBeTrue(); // B still references it

    $b->delete();
    expect(CarrierImportFile::whereKey($file->id)->exists())->toBeFalse(); // last invoice gone
});
