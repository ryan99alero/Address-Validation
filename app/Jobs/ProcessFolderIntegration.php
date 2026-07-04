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

    public int $timeout = 900; // just enumerates + dispatches chunks; the work runs in ProcessFolderChunk

    /** Files per chunk job — sized so a chunk finishes well under ProcessFolderChunk's timeout. */
    public const CHUNK_SIZE = 100;

    public function __construct(
        public FolderIntegration $integration,
        public int $limit = 0,
        public ?string $scanPath = null,
    ) {}

    public function handle(FolderInvoiceIngestService $service): void
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        // Enumerate once, then fan out one retryable ProcessFolderChunk per batch of
        // files. A single oversized folder can no longer time out the whole ingest.
        $files = $service->listCandidates($this->integration, $this->scanPath);
        if ($this->limit > 0) {
            $files = array_slice($files, 0, $this->limit);
        }

        $chunks = array_chunk($files, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            ProcessFolderChunk::dispatch($this->integration, $chunk);
        }

        $this->integration->markChecked('ok');

        Log::info('ProcessFolderIntegration: enumerated', [
            'integration_id' => $this->integration->id,
            'scan_path' => $this->scanPath ?? $this->integration->base_path,
            'files' => count($files),
            'chunks' => count($chunks),
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
