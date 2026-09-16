<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\Carrier;
use App\Models\ImportBatch;
use App\Models\IntegrationConnection;
use App\Models\ShipViaCode;
use App\Services\AddressValidationService;
use App\Services\FedExServiceAvailabilityService;
use App\Services\Shipping\HomeDeliverySwap;
use App\Services\ShippingRecommendationService;
use App\Services\UpsTimeInTransitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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

        // Get addresses that haven't been validated yet (denormalized schema)
        $addressesToValidate = $this->batch->addresses()
            ->where('validation_status', 'pending')
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

        // The shared Fall Back Priority list (used only when a line's carrier is down / unidentified),
        // read from the Pace connection so every path uses the same fallback order.
        $fallbackSlugs = array_values((array) (IntegrationConnection::query()
            ->where('driver', IntegrationConnection::DRIVER_PACE)->where('is_active', true)
            ->value('validation_carriers') ?? []));

        // validation_engine 'auto' = per-row Ship-Via carrier; any carrier slug = force EVERY row to it.
        $engine = (string) ($this->batch->validation_engine ?? 'auto');
        $overrideCarrier = ($engine !== '' && $engine !== 'auto') ? explode('_', $engine)[0] : null;

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
                // Auto: a batch can mix carriers, so group by the carrier resolved from each row's
                // ship_via_code (a row with none drops to the Fall Back Priority list). Override: every
                // row uses the chosen carrier. Either way each group is validated in one bulk call — the
                // per-line carrier drives residential; bulk is preserved (one carrier call per group).
                foreach ($this->groupByCarrier($chunk->all(), $overrideCarrier) as $primarySlug => $group) {
                    $results = $validationService->validateBatchForCarrier(
                        $group,
                        $primarySlug !== '' ? $primarySlug : null,
                        $fallbackSlugs,
                    );
                    foreach ($results as $result) {
                        $validatedCount++;
                        $this->batch->increment('validated_rows');
                    }
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

                // Update service recommendations for addresses with transit times
                try {
                    // Update phase to recommendations
                    $this->batch->update(['processing_phase' => ImportBatch::PHASE_RECOMMENDATIONS]);
                    $this->updateServiceRecommendations();
                } catch (\Exception $e) {
                    Log::error('ProcessImportBatchValidation: Service recommendations failed', [
                        'batch_id' => $this->batch->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Apply BestWay optimization if enabled
                if ($this->batch->find_best_service) {
                    try {
                        $this->batch->update(['processing_phase' => ImportBatch::PHASE_BESTWAY]);
                        $this->applyBestWayOptimization();
                    } catch (\Exception $e) {
                        Log::error('ProcessImportBatchValidation: BestWay optimization failed', [
                            'batch_id' => $this->batch->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Residential FedEx Ground -> Home Delivery, applied LAST so it also corrects a shipment
            // BestWay may have landed on FedEx Ground — a residential shipment never ships Ground.
            try {
                $this->applyResidentialHomeDeliverySwaps();
            } catch (\Exception $e) {
                Log::error('ProcessImportBatchValidation: Home Delivery swap failed', [
                    'batch_id' => $this->batch->id,
                    'error' => $e->getMessage(),
                ]);
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
     * Group a chunk of addresses by the carrier to validate each against. In OVERRIDE mode ($override set)
     * every row goes to that one carrier. In AUTO mode ($override null) rows are grouped by the carrier
     * resolved from their ship_via_code — a mixed-carrier file validates each carrier's rows in one bulk
     * call; a row whose ship-via maps to no carrier gets key '' (→ null primary → the Fall Back list).
     *
     * @param  array<int, Address>  $addresses
     * @return array<string, array<int, Address>>
     */
    protected function groupByCarrier(array $addresses, ?string $override): array
    {
        $groups = [];
        foreach ($addresses as $address) {
            $slug = $override ?? ($this->carrierForAddress($address) ?? '');
            $groups[$slug][] = $address;
        }

        return $groups;
    }

    /**
     * The carrier a row is set to ship on, from its ship_via_code. Null when it has none or the code
     * maps to no carrier (e.g. "Call CSR").
     */
    protected function carrierForAddress(Address $address): ?string
    {
        $code = trim((string) ($address->ship_via_code ?? ''));

        return $code === '' ? null : ShipViaCode::lookup($code)?->carrier_slug;
    }

    /**
     * Residential FedEx Ground -> FedEx Home Delivery for the batch's residential rows, reusing the
     * shared HomeDeliverySwap (same plant/payer/account). Runs after BestWay, so a residential shipment
     * never ships FedEx Ground even if BestWay chose it; the original code is kept in
     * previous_ship_via_code (not clobbering one BestWay already recorded).
     */
    protected function applyResidentialHomeDeliverySwaps(): void
    {
        $swap = new HomeDeliverySwap;

        $this->batch->addresses()
            ->where('is_residential', true)
            ->whereNotNull('ship_via_code')
            ->chunkById(500, function ($rows) use ($swap): void {
                foreach ($rows as $address) {
                    $home = $swap->resolve($address->ship_via_code);
                    if ($home !== null && $home->code !== $address->ship_via_code) {
                        $address->update([
                            'previous_ship_via_code' => $address->previous_ship_via_code ?: $address->ship_via_code,
                            'ship_via_code' => $home->code,
                        ]);
                    }
                }
            });
    }

    /**
     * Fetch transit times for the batch's validated addresses.
     *
     * Override (transit_carrier_id set): one carrier for the whole batch. Auto (null): each row's
     * transit is looked up on the carrier its Ship-Via maps to — a mixed-carrier file gets correct
     * times. Transit APIs exist for UPS + FedEx, so any other carrier (Smarty/USPS/unknown) uses FedEx.
     */
    protected function fetchTransitTimes(): void
    {
        $total = $this->batch->addresses()->where('validation_status', 'valid')->count();
        $this->batch->update([
            'processing_phase' => ImportBatch::PHASE_TRANSIT_TIMES,
            'total_for_transit' => $total,
            'transit_time_rows' => 0,
        ]);

        // Override: one carrier for every row.
        if ($this->batch->transit_carrier_id) {
            $carrier = Carrier::where('id', $this->batch->transit_carrier_id)->where('is_active', true)->first();
            if (! $carrier) {
                Log::warning('ProcessImportBatchValidation: Transit carrier not found or inactive', [
                    'batch_id' => $this->batch->id, 'transit_carrier_id' => $this->batch->transit_carrier_id,
                ]);

                return;
            }
            $this->fetchTransitTimesForCarrier($carrier, $this->batch->addresses()->where('validation_status', 'valid'));

            return;
        }

        // Auto: group valid addresses by their Ship-Via carrier (UPS/FedEx; anything else -> FedEx) and
        // look each group up on that carrier. Progress accumulates across groups.
        $processedBase = 0;
        foreach ($this->transitCarrierGroups() as $slug => $ids) {
            if ($ids === []) {
                continue;
            }
            $carrier = Carrier::where('slug', $slug)->where('is_active', true)->first();
            if (! $carrier) {
                continue;
            }
            $processedBase += $this->fetchTransitTimesForCarrier(
                $carrier,
                $this->batch->addresses()->where('validation_status', 'valid')->whereIn('id', $ids),
                $processedBase,
            );
        }
    }

    /**
     * Run transit lookups for one carrier over the given address query, in concurrent chunks. Returns
     * the number processed; $progressBase offsets the batch's transit_time_rows so Auto mode's several
     * carrier groups report a single running total.
     *
     * @param  Builder<Address>  $addressQuery
     */
    protected function fetchTransitTimesForCarrier(Carrier $carrier, $addressQuery, int $progressBase = 0): int
    {
        $transitService = match ($carrier->slug) {
            'ups' => new UpsTimeInTransitService($carrier),
            default => new FedExServiceAvailabilityService($carrier),
        };

        $concurrentRequests = $carrier->concurrent_requests ?? 10;
        $chunkSize = $concurrentRequests * 5;
        $processed = 0;
        $failed = 0;

        Log::info('ProcessImportBatchValidation: Fetching transit times', [
            'batch_id' => $this->batch->id, 'carrier' => $carrier->name, 'origin_postal_code' => $this->batch->origin_postal_code,
        ]);

        $addressQuery->chunk($chunkSize, function ($addresses) use ($transitService, $concurrentRequests, $progressBase, &$processed, &$failed) {
            $this->batch->refresh();
            if ($this->batch->isCancelled()) {
                return false;
            }

            $result = $transitService->getTransitTimesBatch(
                $addresses,
                $this->batch->origin_postal_code,
                $this->batch->origin_country_code ?? 'US',
                $concurrentRequests
            );
            $processed += $result['processed'];
            $failed += $result['failed'];
            $this->batch->update(['transit_time_rows' => $progressBase + $processed]);

            if ($failed > 10 && $processed === 0) {
                Log::error('ProcessImportBatchValidation: Too many transit time failures, stopping', ['batch_id' => $this->batch->id]);

                return false;
            }

            return true;
        });

        return $processed;
    }

    /**
     * Valid-address ids grouped by the carrier to run transit on (Auto mode). Only UPS + FedEx have
     * transit APIs, so a row whose Ship-Via maps to neither is looked up on FedEx.
     *
     * @return array<string, array<int, int>>
     */
    protected function transitCarrierGroups(): array
    {
        $groups = ['fedex' => [], 'ups' => []];
        $this->batch->addresses()->where('validation_status', 'valid')
            ->select('id', 'ship_via_code')
            ->chunkById(1000, function ($rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $slug = $this->carrierForAddress($row);
                    $groups[in_array($slug, ['ups', 'fedex'], true) ? $slug : 'fedex'][] = $row->id;
                }
            });

        return $groups;
    }

    /**
     * Update service recommendations based on available data.
     *
     * Smart logic:
     * - If ship_via present: Calculate transit info for that service
     * - If dates present (no ship_via): Recommend best service to meet deadline
     * - If both present: Validate ship_via meets deadline, suggest alternative if not
     * - Always: Calculate fastest service, distance, and other calculable fields
     */
    protected function updateServiceRecommendations(): void
    {
        $recommendationService = new ShippingRecommendationService;

        // Get ALL addresses with transit times
        // Include shipViaCodeRecord for ship_via analysis
        $addressesWithTransitTimes = $this->batch->addresses()
            ->whereHas('transitTimes')
            ->with(['transitTimes', 'shipViaCodeRecord'])
            ->get();

        if ($addressesWithTransitTimes->isEmpty()) {
            Log::info('ProcessImportBatchValidation: No addresses with transit times', [
                'batch_id' => $this->batch->id,
            ]);

            return;
        }

        // Count addresses with different data combinations
        $withShipVia = $addressesWithTransitTimes->filter(fn ($a) => ! empty($a->ship_via_code))->count();
        $withDates = $addressesWithTransitTimes->filter(fn ($a) => $a->required_on_site_date !== null)->count();
        $withBoth = $addressesWithTransitTimes->filter(fn ($a) => ! empty($a->ship_via_code) && $a->required_on_site_date !== null)->count();

        Log::info('ProcessImportBatchValidation: Updating service recommendations', [
            'batch_id' => $this->batch->id,
            'addresses_count' => $addressesWithTransitTimes->count(),
            'with_ship_via' => $withShipVia,
            'with_dates' => $withDates,
            'with_both' => $withBoth,
        ]);

        $result = $recommendationService->calculateRecommendationsBatch($addressesWithTransitTimes);

        Log::info('ProcessImportBatchValidation: Service recommendations completed', [
            'batch_id' => $this->batch->id,
            'processed' => $result['processed'],
            'with_recommendations' => $result['with_recommendations'],
            'with_ship_via' => $result['with_ship_via'],
            'with_suggestions' => $result['with_suggestions'],
        ]);

        // Note: recommended_ship_date (the latest JIT ship date) is set by BestWay
        // optimization, which is account/plant-constrained. The unconstrained
        // reverse-schedule pass was removed so the ship date always reflects a
        // service the address can actually use.
    }

    /**
     * Apply BestWay optimization to find the most economical shipping service.
     *
     * This replaces ship_via_code with the cheapest service that meets the Required On-Site Date.
     * The original ship_via_code is preserved in previous_ship_via_code.
     */
    protected function applyBestWayOptimization(): void
    {
        $recommendationService = new ShippingRecommendationService;

        // Get addresses with required_on_site_date and transit times
        $addressesToOptimize = $this->batch->addresses()
            ->whereNotNull('required_on_site_date')
            ->whereHas('transitTimes')
            ->with(['transitTimes', 'shipViaCodeRecord'])
            ->get();

        if ($addressesToOptimize->isNotEmpty()) {
            Log::info('ProcessImportBatchValidation: Applying BestWay optimization', [
                'batch_id' => $this->batch->id,
                'addresses_count' => $addressesToOptimize->count(),
            ]);

            $result = $recommendationService->applyBestWayOptimizationBatch($addressesToOptimize, $this->batch->bestway_plant_id, $this->batch->bestway_carrier_account_id, (bool) $this->batch->bestway_account_strict);

            Log::info('ProcessImportBatchValidation: BestWay optimization completed', [
                'batch_id' => $this->batch->id,
                'processed' => $result['processed'],
                'optimized' => $result['optimized'],
                'already_optimal' => $result['already_optimal'],
                'no_viable_service' => $result['no_viable_service'],
                'no_matching_code' => $result['no_matching_code'] ?? 0,
            ]);

            if ($this->batch->reverse_validate_future_date) {
                $this->reverseValidateFutureDates();
            }
        } else {
            Log::info('ProcessImportBatchValidation: No addresses to optimize with BestWay', [
                'batch_id' => $this->batch->id,
            ]);
        }

        // In a BestWay-enabled batch every address resolves to a definite Yes/No on BOTH flags. Anything
        // the engine couldn't evaluate (no on-site date / no transit times, or a service path that left
        // it blank) defaults to No — so neither bestway_optimized nor ship_via_meets_deadline (whether
        // it arrives by the requested date) is ever blank in the export.
        $this->batch->addresses()->whereNull('bestway_optimized')->update(['bestway_optimized' => false]);
        $this->batch->addresses()->whereNull('ship_via_meets_deadline')->update(['ship_via_meets_deadline' => false]);
    }

    /**
     * Opt-in second pass: for BestWay-optimized shipments with a FUTURE ship date, re-quote
     * FedEx at that ship date to confirm the committed (holiday-aware) delivery is on time,
     * setting the real arrival + arrival_verified. One extra API call per future-dated
     * shipment — that's why it's gated behind the batch toggle. FedEx only for now.
     */
    protected function reverseValidateFutureDates(): void
    {
        $transitCarrier = $this->batch->transit_carrier_id
            ? Carrier::where('id', $this->batch->transit_carrier_id)->where('is_active', true)->first()
            : null;

        if (! $transitCarrier || $transitCarrier->slug !== 'fedex') {
            return;
        }

        $addresses = $this->batch->addresses()
            ->where('bestway_optimized', true)
            ->whereNotNull('recommended_ship_date')
            ->whereDate('recommended_ship_date', '>', now()->toDateString())
            ->get();

        if ($addresses->isEmpty()) {
            return;
        }

        $counts = (new ShippingRecommendationService)->reverseValidateArrivalBatch(
            $addresses,
            new FedExServiceAvailabilityService($transitCarrier)
        );

        Log::info('ProcessImportBatchValidation: Reverse-validated future ship dates', array_merge(
            ['batch_id' => $this->batch->id],
            $counts
        ));
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
