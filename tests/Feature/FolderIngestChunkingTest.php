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
    // 250 files over CHUNK_SIZE=100 -> 3 chunks (100 + 100 + 50).
    for ($i = 0; $i < 250; $i++) {
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

    Bus::assertDispatchedTimes(ProcessFolderChunk::class, 3);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
