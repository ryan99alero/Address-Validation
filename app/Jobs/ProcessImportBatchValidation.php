<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\AddressValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;

class ProcessImportBatchValidation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600; // 1 hour max

    /**
     * @param  int  $concurrency  Number of addresses to validate in parallel per batch
     */
    public function __construct(
        public ImportBatch $batch,
        public int $concurrency = 10
    ) {}

    public function handle(AddressValidationService $validationService): void
    {
        // Disable Telescope for this job - it stores all queries in memory
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        // Disable query log to save memory
        DB::disableQueryLog();

        $addressesToValidate = $this->batch->addresses()
            ->whereDoesntHave('corrections')
            ->get();

        Log::info('ProcessImportBatchValidation: Starting', [
            'batch_id' => $this->batch->id,
            'addresses_to_validate' => $addressesToValidate->count(),
            'concurrency' => $this->concurrency,
        ]);

        $carrier = $this->batch->carrier;

        if (! $carrier || ! $carrier->is_active) {
            Log::error('ProcessImportBatchValidation: No active carrier', [
                'batch_id' => $this->batch->id,
            ]);
            $this->batch->markFailed();

            return;
        }

        if ($addressesToValidate->isEmpty()) {
            Log::info('ProcessImportBatchValidation: No addresses to validate', [
                'batch_id' => $this->batch->id,
            ]);
            $this->batch->markCompleted();

            return;
        }

        // Mark batch as processing
        $this->batch->update(['status' => ImportBatch::STATUS_PROCESSING]);

        $validatedCount = 0;
        $failedCount = 0;

        // Use the carrier's configured chunk_size for batch processing
        // The carrier service handles concurrency internally based on its settings
        $batchSize = $carrier->chunk_size ?? 100;

        Log::info('ProcessImportBatchValidation: Using batch settings', [
            'batch_id' => $this->batch->id,
            'chunk_size' => $batchSize,
            'concurrent_requests' => $carrier->concurrent_requests,
            'supports_native_batch' => $carrier->supports_native_batch,
        ]);

        // Process in batches - carrier handles concurrency internally
        foreach ($addressesToValidate->chunk($batchSize) as $chunk) {
            // Check if cancelled
            $this->batch->refresh();
            if ($this->batch->isCancelled()) {
                Log::info('ProcessImportBatchValidation: Cancelled by user', [
                    'batch_id' => $this->batch->id,
                    'validated_so_far' => $validatedCount,
                ]);
                break;
            }

            try {
                // Use native batch validation (FedEx/Smarty) or concurrent (UPS)
                $corrections = $validationService->validateBatch($chunk->all(), $carrier->slug);

                // Process results
                foreach ($corrections as $correction) {
                    $validatedCount++;
                    $this->batch->increment('validated_rows');
                }
            } catch (\Exception $e) {
                Log::warning('ProcessImportBatchValidation: Batch failed', [
                    'batch_id' => $this->batch->id,
                    'chunk_size' => $chunk->count(),
                    'error' => $e->getMessage(),
                ]);
                $failedCount += $chunk->count();
            }
        }

        // Final status
        $this->batch->refresh();
        if (! $this->batch->isCancelled()) {
            $this->batch->markCompleted();
        }

        Log::info('ProcessImportBatchValidation: Completed', [
            'batch_id' => $this->batch->id,
            'validated' => $validatedCount,
            'failed' => $failedCount,
            'was_cancelled' => $this->batch->isCancelled(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessImportBatchValidation: Job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->markFailed();
    }
}
