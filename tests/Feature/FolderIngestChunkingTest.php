<?php

use App\Jobs\ProcessFolderChunk;
use App\Jobs\ProcessFolderIntegration;
use App\Models\Carrier;
use App\Models\FolderIntegration;
use App\Services\Invoices\FolderInvoiceIngestService;
use Illuminate\Support\Facades\Bus;

test('folder integration enumerates once and fans out one chunk job per batch', function () {
    Bus::fake([ProcessFolderChunk::class]);

    $carrier = Carrier::factory()->create(['slug' => 'ups']);

    $dir = sys_get_temp_dir().'/folderingest_'.uniqid();
    mkdir($dir);
    // Fan out one chunk per CHUNK_SIZE files (kept small so heavy batch PDFs can't blow the
    // chunk timeout). Use a multiple of CHUNK_SIZE so the count is exact.
    $fileCount = ProcessFolderIntegration::CHUNK_SIZE * 4;
    for ($i = 0; $i < $fileCount; $i++) {
        file_put_contents($dir.'/f'.$i.'.csv', 'x');
    }

    $folder = FolderIntegration::create([
        'name' => 'Test Local',
        'is_active' => true,
        'carrier_id' => $carrier->id,
        'connection_type' => FolderIntegration::TYPE_LOCAL,
        'base_path' => $dir,
        'file_pattern' => '*.csv',
        'recursive' => false,
    ]);

    (new ProcessFolderIntegration($folder))->handle(app(FolderInvoiceIngestService::class));

    Bus::assertDispatchedTimes(ProcessFolderChunk::class, 4);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
