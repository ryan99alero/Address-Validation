<?php

use App\Models\Carrier;
use App\Models\CarrierImportFile;
use App\Models\CarrierInvoice;
use App\Services\Invoices\SourceFileFetcher;
use Illuminate\Support\Facades\Storage;

it('falls back to the invoice archived PDF when the source file is gone (mail ingest)', function () {
    Storage::fake('local');
    Storage::disk('local')->put('invoices/processed/UPS/2026/07/Invoice_691317286.PDF', '%PDF-1.4 fake');

    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id, 'invoice_number' => '691317286',
        'invoice_date' => '2026-07-11', 'status' => 'pending',
        'archived_path' => 'invoices/processed/UPS/2026/07/Invoice_691317286.PDF',
    ]);
    $file = CarrierImportFile::create([
        'carrier_id' => $carrier->id,
        'filename' => 'Invoice_691317286.PDF',
        'source_reference' => '/tmp/gone/email-attachment.pdf', // no longer on disk
        'file_hash' => 'hash1',
    ]);
    $file->invoices()->attach($invoice->id);

    $result = app(SourceFileFetcher::class)->toLocalPath($file);

    expect(is_file($result['path']))->toBeTrue()
        ->and($result['cleanup'])->toBeFalse();
});

it('throws when neither the source nor an archive copy exists', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $file = CarrierImportFile::create([
        'carrier_id' => $carrier->id,
        'filename' => 'missing.pdf',
        'source_reference' => '/tmp/gone/missing.pdf',
        'file_hash' => 'hash2',
    ]);

    expect(fn () => app(SourceFileFetcher::class)->toLocalPath($file))
        ->toThrow(RuntimeException::class);
});
