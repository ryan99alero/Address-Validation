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

class ProcessFolderIntegration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200; // 2 hours for a full multi-year scan

    public function __construct(
        public FolderIntegration $integration,
        public int $limit = 0,
        public ?string $scanPath = null,
    ) {}

    public function handle(FolderInvoiceIngestService $service): void
    {
        // Long-running job: stop Telescope and the query log from holding every
        // query in memory (thousands of inserts per year folder would OOM).
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        $stats = $service->ingest($this->integration, $this->limit, $this->scanPath);
        $this->integration->markChecked('ok');

        Log::info('ProcessFolderIntegration: completed', [
            'integration_id' => $this->integration->id,
            'scan_path' => $this->scanPath ?? $this->integration->base_path,
            'found' => $stats['found'],
            'processed' => $stats['processed'],
            'skipped' => $stats['skipped'],
            'failed' => $stats['failed'],
            'errors' => array_slice($stats['errors'], 0, 20),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->integration->markChecked('error', $exception->getMessage());

        Log::error('ProcessFolderIntegration: failed', [
            'integration_id' => $this->integration->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
