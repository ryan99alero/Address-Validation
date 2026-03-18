<?php

namespace App\Jobs;

use App\Models\Carrier;
use App\Models\ImportBatch;
use App\Services\AddressValidationService;
use App\Services\FedExServiceAvailabilityService;
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

        // Mark batch as processing - validation phase
        $this->batch->update([
            'status' => ImportBatch::STATUS_PROCESSING,
            'processing_phase' => ImportBatch::PHASE_VALIDATING,
        ]);

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
            // Fetch transit times if enabled (wrapped in try/catch to ensure completion)
            if ($this->batch->include_transit_times && $this->batch->origin_postal_code) {
                try {
                    $this->fetchTransitTimes();
                } catch (\Exception $e) {
                    Log::error('ProcessImportBatchValidation: Transit times failed entirely', [
                        'batch_id' => $this->batch->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to mark batch completed even if transit times fail
                }

                // Update service recommendations for addresses with required delivery dates
                try {
                    $this->updateServiceRecommendations();
                } catch (\Exception $e) {
                    Log::error('ProcessImportBatchValidation: Service recommendations failed', [
                        'batch_id' => $this->batch->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->batch->markCompleted();
        }

        Log::info('ProcessImportBatchValidation: Completed', [
            'batch_id' => $this->batch->id,
            'validated' => $validatedCount,
            'failed' => $failedCount,
            'was_cancelled' => $this->batch->isCancelled(),
        ]);
    }

    /**
     * Fetch transit times for all validated addresses in the batch.
     */
    protected function fetchTransitTimes(): void
    {
        // Only FedEx supports transit times currently
        $fedexCarrier = Carrier::where('slug', 'fedex')->where('is_active', true)->first();

        if (! $fedexCarrier) {
            Log::warning('ProcessImportBatchValidation: FedEx carrier not found or inactive for transit times', [
                'batch_id' => $this->batch->id,
            ]);

            return;
        }

        // Get validated addresses count first
        $totalValidated = $this->batch->addresses()
            ->whereHas('corrections', fn ($q) => $q->where('validation_status', 'valid'))
            ->count();

        // Update phase to transit times
        $this->batch->update([
            'processing_phase' => ImportBatch::PHASE_TRANSIT_TIMES,
            'total_for_transit' => $totalValidated,
            'transit_time_rows' => 0,
        ]);

        Log::info('ProcessImportBatchValidation: Fetching transit times', [
            'batch_id' => $this->batch->id,
            'origin_postal_code' => $this->batch->origin_postal_code,
            'total_addresses' => $totalValidated,
        ]);

        $transitService = new FedExServiceAvailabilityService($fedexCarrier);

        $processed = 0;
        $failed = 0;

        // Use carrier's concurrent request setting, default to 10
        $concurrentRequests = $fedexCarrier->concurrent_requests ?? 10;
        $chunkSize = $concurrentRequests * 5; // Process 5 concurrent batches at a time

        Log::info('ProcessImportBatchValidation: Using concurrent transit time fetching', [
            'batch_id' => $this->batch->id,
            'concurrent_requests' => $concurrentRequests,
            'chunk_size' => $chunkSize,
        ]);

        // Process in chunks using cursor for memory efficiency
        $this->batch->addresses()
            ->whereHas('corrections', fn ($q) => $q->where('validation_status', 'valid'))
            ->with('latestCorrection')
            ->chunk($chunkSize, function ($addresses) use ($transitService, $concurrentRequests, &$processed, &$failed) {
                // Check if cancelled at chunk boundary
                $this->batch->refresh();
                if ($this->batch->isCancelled()) {
                    return false; // Stop chunking
                }

                // Use concurrent batch processing
                $result = $transitService->getTransitTimesBatch(
                    $addresses,
                    $this->batch->origin_postal_code,
                    $this->batch->origin_country_code ?? 'US',
                    $concurrentRequests
                );

                $processed += $result['processed'];
                $failed += $result['failed'];

                // Update progress after each chunk
                $this->batch->update(['transit_time_rows' => $processed]);

                // If we get too many consecutive failures, stop trying
                if ($failed > 10 && $processed === 0) {
                    Log::error('ProcessImportBatchValidation: Too many transit time failures, stopping', [
                        'batch_id' => $this->batch->id,
                    ]);

                    return false; // Stop chunking
                }

                return true; // Continue to next chunk
            });

        // Final update of transit time progress
        $this->batch->update(['transit_time_rows' => $processed]);

        Log::info('ProcessImportBatchValidation: Transit times completed', [
            'batch_id' => $this->batch->id,
            'processed' => $processed,
            'failed' => $failed,
        ]);
    }

    /**
     * Update service recommendations based on required delivery dates.
     * Finds the most economical (cheapest) service that can meet each address's delivery requirement.
     */
    protected function updateServiceRecommendations(): void
    {
        // Service types ordered by cost (cheapest to most expensive)
        // Ground services are cheapest, then express saver, then overnight services
        $serviceEconomyOrder = [
            'FEDEX_GROUND' => 1,
            'GROUND_HOME_DELIVERY' => 1,
            'SMART_POST' => 2,
            'FEDEX_EXPRESS_SAVER' => 3,
            'FEDEX_2_DAY' => 4,
            'FEDEX_2_DAY_AM' => 5,
            'STANDARD_OVERNIGHT' => 6,
            'PRIORITY_OVERNIGHT' => 7,
            'FIRST_OVERNIGHT' => 8,
        ];

        // Get addresses with required on-site dates and transit times
        $addressesWithRequirements = $this->batch->addresses()
            ->whereNotNull('required_on_site_date')
            ->whereHas('transitTimes')
            ->with('transitTimes')
            ->get();

        if ($addressesWithRequirements->isEmpty()) {
            Log::info('ProcessImportBatchValidation: No addresses with required on-site dates', [
                'batch_id' => $this->batch->id,
            ]);

            return;
        }

        Log::info('ProcessImportBatchValidation: Updating service recommendations', [
            'batch_id' => $this->batch->id,
            'addresses_count' => $addressesWithRequirements->count(),
        ]);

        $updated = 0;
        $cannotMeet = 0;

        foreach ($addressesWithRequirements as $address) {
            $requiredDate = $address->required_on_site_date;
            $transitTimes = $address->transitTimes;

            if ($transitTimes->isEmpty()) {
                continue;
            }

            // Find services that can meet the required date, sorted by economy
            $eligibleServices = $transitTimes
                ->filter(function ($transitTime) use ($requiredDate) {
                    // Service is eligible if delivery_date is on or before required date
                    return $transitTime->delivery_date &&
                           $transitTime->delivery_date->lte($requiredDate);
                })
                ->sortBy(function ($transitTime) use ($serviceEconomyOrder) {
                    // Sort by economy (lowest number = cheapest)
                    return $serviceEconomyOrder[$transitTime->service_type] ?? 999;
                });

            if ($eligibleServices->isNotEmpty()) {
                // Pick the most economical service that meets the requirement
                $recommendedService = $eligibleServices->first();

                $address->update([
                    'recommended_service' => $recommendedService->service_label,
                    'estimated_delivery_date' => $recommendedService->delivery_date,
                    'can_meet_required_date' => true,
                ]);
                $updated++;
            } else {
                // No service can meet the required date - recommend the fastest available
                $fastestService = $transitTimes
                    ->sortBy(function ($transitTime) {
                        return $transitTime->delivery_date ?? now()->addYears(10);
                    })
                    ->first();

                $address->update([
                    'recommended_service' => $fastestService?->service_label,
                    'estimated_delivery_date' => $fastestService?->delivery_date,
                    'can_meet_required_date' => false,
                ]);
                $cannotMeet++;
            }
        }

        Log::info('ProcessImportBatchValidation: Service recommendations completed', [
            'batch_id' => $this->batch->id,
            'updated' => $updated,
            'cannot_meet_required' => $cannotMeet,
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
