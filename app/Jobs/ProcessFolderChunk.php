<?php

namespace App\Jobs;

use App\Models\FolderIntegration;
use App\Services\Invoices\FolderInvoiceIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Throwable;

/**
 * Import one chunk of files from a folder integration. Chunks are individually
 * retryable and run in parallel across workers, so one oversized folder can't time
 * out the whole year's ingest. Files are content-hash deduped, so a retried chunk
 * only redoes what it hadn't finished.
 */
class ProcessFolderChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 min per ~100-file chunk

    /**
     * @param  array<int, string>  $files
     */
    public function __construct(
        public FolderIntegration $integration,
        public array $files,
    ) {}

    public function handle(FolderInvoiceIngestService $service): void
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        $stats = $service->ingestFiles($this->integration, $this->files);

        Log::info('ProcessFolderChunk: completed', [
            'integration_id' => $this->integration->id,
            'files' => count($this->files),
            'processed' => $stats['processed'],
            'skipped' => $stats['skipped'],
            'failed' => $stats['failed'],
            'errors' => array_slice($stats['errors'], 0, 20),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessFolderChunk: failed', [
            'integration_id' => $this->integration->id,
            'files' => count($this->files),
            'error' => $exception->getMessage(),
        ]);
    }
}
