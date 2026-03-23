<?php

namespace App\Services;

use App\Models\Address;
use App\Models\ShipViaCode;
use App\Models\TransitTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ShippingRecommendationService
{
    /**
     * Service type cost ranking (lower = cheaper).
     * Used to recommend the most economical service that meets the deadline.
     */
    protected const SERVICE_COST_RANK = [
        // Ground services (cheapest)
        'FEDEX_GROUND' => 10,
        'GROUND_HOME_DELIVERY' => 11,
        'SMART_POST' => 12,

        // Economy express
        'FEDEX_EXPRESS_SAVER' => 20,
        'FEDEX_2_DAY' => 30,
        'FEDEX_2_DAY_AM' => 35,

        // Overnight services (most expensive)
        'STANDARD_OVERNIGHT' => 40,
        'PRIORITY_OVERNIGHT' => 50,
        'FIRST_OVERNIGHT' => 60,

        // Freight (special pricing)
        'FEDEX_FREIGHT_ECONOMY' => 15,
        'FEDEX_FREIGHT_PRIORITY' => 25,

        // International
        'INTERNATIONAL_ECONOMY' => 22,
        'INTERNATIONAL_PRIORITY' => 45,
        'INTERNATIONAL_FIRST' => 55,
    ];

    /**
     * Calculate shipping recommendations for an address using smart logic.
     *
     * Logic flow:
     * 1. If ship_via is empty AND dates present → recommend best service to meet deadline
     * 2. If ship_via is present → calculate transit info for that service
     * 3. If both present → validate ship_via meets deadline, suggest alternative if not
     * 4. Always populate fastest service, distance, and other calculable fields
     */
    public function calculateRecommendations(Address $address): Address
    {
        // Use already-loaded relationship if available, otherwise load it
        $transitTimes = $address->relationLoaded('transitTimes')
            ? $address->transitTimes
            : $address->transitTimes()->get();

        if ($transitTimes->isEmpty()) {
            return $this->clearRecommendations($address);
        }

        // Always populate fastest service
        $this->populateFastestService($address, $transitTimes);

        // Always populate distance if available
        $this->populateDistance($address, $transitTimes);

        // Resolve ship_via_code to ship_via_code_id if needed
        $this->resolveShipViaCode($address);

        // Get the ship via service type if we have one
        $shipViaServiceType = $this->getShipViaServiceType($address);

        // SCENARIO 1: Ship via is present - calculate transit info for that service
        if ($shipViaServiceType) {
            $this->populateShipViaInfo($address, $transitTimes, $shipViaServiceType);

            // If we have dates, check if ship_via meets the deadline
            if ($address->required_on_site_date) {
                $this->validateShipViaMeetsDeadline($address, $transitTimes);
            }
        }

        // SCENARIO 2: No ship via but have dates - recommend best service
        // Or SCENARIO 3: Ship via doesn't meet deadline - recommend alternative
        if ($address->required_on_site_date) {
            $this->populateRecommendedService($address, $transitTimes);
        }

        $address->save();

        return $address;
    }

    /**
     * Calculate recommendations for multiple addresses using bulk updates.
     *
     * @param  Collection<int, Address>  $addresses
     * @return array{processed: int, with_recommendations: int, with_ship_via: int, with_suggestions: int}
     */
    public function calculateRecommendationsBatch(Collection $addresses): array
    {
        $processed = 0;
        $withRecommendations = 0;
        $withShipVia = 0;
        $withSuggestions = 0;

        // Collect all updates for bulk processing
        $updates = [];

        foreach ($addresses as $address) {
            // Calculate recommendations without saving
            $this->calculateRecommendationsWithoutSave($address);
            $processed++;

            if ($address->recommended_service || $address->fastest_service) {
                $withRecommendations++;
            }

            if ($address->ship_via_service) {
                $withShipVia++;
            }

            if ($address->suggested_service) {
                $withSuggestions++;
            }

            // Collect dirty attributes for bulk update
            if ($address->isDirty()) {
                $updates[$address->id] = $address->getDirty();
            }
        }

        // Bulk update all addresses
        foreach ($updates as $addressId => $data) {
            Address::where('id', $addressId)->update($data);
        }

        return [
            'processed' => $processed,
            'with_recommendations' => $withRecommendations,
            'with_ship_via' => $withShipVia,
            'with_suggestions' => $withSuggestions,
        ];
    }

    /**
     * Calculate recommendations for a single address without saving.
     * Used by batch processing for bulk updates.
     */
    protected function calculateRecommendationsWithoutSave(Address $address): void
    {
        // Use already-loaded relationship if available, otherwise load it
        $transitTimes = $address->relationLoaded('transitTimes')
            ? $address->transitTimes
            : $address->transitTimes()->get();

        if ($transitTimes->isEmpty()) {
            $this->clearRecommendationsWithoutSave($address);

            return;
        }

        // Always populate fastest service
        $this->populateFastestService($address, $transitTimes);

        // Always populate distance if available
        $this->populateDistance($address, $transitTimes);

        // Resolve ship_via_code to ship_via_code_id if needed
        $this->resolveShipViaCode($address);

        // Get the ship via service type if we have one
        $shipViaServiceType = $this->getShipViaServiceType($address);

        // SCENARIO 1: Ship via is present - calculate transit info for that service
        if ($shipViaServiceType) {
            $this->populateShipViaInfo($address, $transitTimes, $shipViaServiceType);

            // If we have dates, check if ship_via meets the deadline
            if ($address->required_on_site_date) {
                $this->validateShipViaMeetsDeadline($address, $transitTimes);
            }
        }

        // SCENARIO 2: No ship via but have dates - recommend best service
        // Or SCENARIO 3: Ship via doesn't meet deadline - recommend alternative
        if ($address->required_on_site_date) {
            $this->populateRecommendedService($address, $transitTimes);
        }
    }

    /**
     * Clear recommendation fields without saving.
     */
    protected function clearRecommendationsWithoutSave(Address $address): void
    {
        $address->recommended_service = null;
        $address->estimated_delivery_date = null;
        $address->can_meet_required_date = null;
        $address->fastest_service = null;
        $address->fastest_date = null;
        $address->ship_via_service = null;
        $address->ship_via_days = null;
        $address->ship_via_date = null;
        $address->ship_via_meets_deadline = null;
        $address->suggested_service = null;
        $address->suggested_delivery_date = null;
        $address->distance_miles = null;
    }

    /**
     * Resolve ship_via_code string to ship_via_code_id foreign key.
     */
    protected function resolveShipViaCode(Address $address): void
    {
        // If we already have ship_via_code_id, skip
        if ($address->ship_via_code_id) {
            return;
        }

        // If no ship_via_code string, nothing to resolve
        if (empty($address->ship_via_code)) {
            return;
        }

        // Use preloaded relationship if available
        if ($address->relationLoaded('shipViaCodeRecord') && $address->shipViaCodeRecord) {
            $address->ship_via_code_id = $address->shipViaCodeRecord->id;

            return;
        }

        // Fallback: Look up the ShipViaCode record (single address validation)
        $shipViaCodeRecord = ShipViaCode::lookup($address->ship_via_code);

        if ($shipViaCodeRecord) {
            $address->ship_via_code_id = $shipViaCodeRecord->id;
        }
    }

    /**
     * Get the service type for the address's ship via code.
     */
    protected function getShipViaServiceType(Address $address): ?string
    {
        // If we have a ship_via_code_id, load the record
        if ($address->ship_via_code_id) {
            $shipViaCodeRecord = $address->shipViaCodeRecord;
            if ($shipViaCodeRecord) {
                return $shipViaCodeRecord->service_type;
            }
        }

        // If we have a ship_via_code string, try to map it directly
        if ($address->ship_via_code) {
            $upperCode = strtoupper($address->ship_via_code);

            // Check if it's a known carrier code
            if (isset(ShipViaCode::CARRIER_CODE_MAP[$upperCode])) {
                return ShipViaCode::CARRIER_CODE_MAP[$upperCode]['service_type'];
            }

            // Maybe it's already a service type
            if (isset(ShipViaCode::SERVICE_TYPE_LABELS[$upperCode])) {
                return $upperCode;
            }
        }

        return null;
    }

    /**
     * Populate ship via transit info from transit times.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function populateShipViaInfo(Address $address, Collection $transitTimes, string $serviceType): void
    {
        $transitTime = $transitTimes->firstWhere('service_type', $serviceType);

        if ($transitTime) {
            $serviceName = $transitTime->service_name
                ?: (ShipViaCode::SERVICE_TYPE_LABELS[$serviceType] ?? $serviceType);
            $address->ship_via_service = $this->sanitizeServiceName($serviceName);
            $address->ship_via_days = $transitTime->getCalculatedTransitDays();
            $address->ship_via_date = $transitTime->delivery_date;
        } else {
            // Service type exists but no transit time data for it
            $address->ship_via_service = $this->sanitizeServiceName(
                ShipViaCode::SERVICE_TYPE_LABELS[$serviceType] ?? $serviceType
            );
            $address->ship_via_days = null;
            $address->ship_via_date = null;
        }
    }

    /**
     * Sanitize service name by removing trademark symbols and special characters.
     */
    protected function sanitizeServiceName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Remove trademark, registered, and other special symbols
        $name = str_replace(['®', '™', '©', '℠'], '', $name);

        // Also handle encoded versions that might appear
        $name = preg_replace('/[\x{00AE}\x{2122}\x{00A9}\x{2120}]/u', '', $name);

        // Clean up any double spaces and trim
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Check if ship_via meets the required on-site date.
     * If not, populate suggested_service with an alternative.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function validateShipViaMeetsDeadline(Address $address, Collection $transitTimes): void
    {
        $requiredDate = $address->required_on_site_date;

        // Check if ship_via delivery date meets the deadline
        if ($address->ship_via_date) {
            $meetsDeadline = $address->ship_via_date->lte($requiredDate);
            $address->ship_via_meets_deadline = $meetsDeadline;

            // If ship_via doesn't meet deadline, suggest an alternative
            if (! $meetsDeadline) {
                $this->populateSuggestedService($address, $transitTimes);
            } else {
                // Ship via meets deadline, no suggestion needed
                $address->suggested_service = null;
                $address->suggested_delivery_date = null;
            }
        } else {
            // No delivery date for ship_via - can't determine if it meets deadline
            $address->ship_via_meets_deadline = null;
        }
    }

    /**
     * Populate suggested service when ship_via doesn't meet deadline.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function populateSuggestedService(Address $address, Collection $transitTimes): void
    {
        $requiredDate = $address->required_on_site_date;

        // Find the most economical service that meets the deadline
        $viableServices = $this->findServicesMeetingDeadline($transitTimes, $requiredDate);

        if ($viableServices->isEmpty()) {
            // No service can meet the deadline
            $address->suggested_service = null;
            $address->suggested_delivery_date = null;

            return;
        }

        $suggested = $this->findMostEconomicalService($viableServices);

        $address->suggested_service = $this->sanitizeServiceName($suggested->service_name ?: $suggested->service_type);
        $address->suggested_delivery_date = $suggested->delivery_date;
    }

    /**
     * Populate recommended service for deadline-based recommendations.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function populateRecommendedService(Address $address, Collection $transitTimes): void
    {
        $requiredDate = $address->required_on_site_date;

        // Find services that can meet the deadline
        $viableServices = $this->findServicesMeetingDeadline($transitTimes, $requiredDate);

        if ($viableServices->isEmpty()) {
            // No service can meet the deadline
            $address->can_meet_required_date = false;
            $address->recommended_service = null;
            $address->estimated_delivery_date = null;
        } else {
            // Find the most economical service that meets deadline
            $recommended = $this->findMostEconomicalService($viableServices);

            $address->can_meet_required_date = true;
            $address->recommended_service = $this->sanitizeServiceName($recommended->service_name ?: $recommended->service_type);
            $address->estimated_delivery_date = $recommended->delivery_date;
        }
    }

    /**
     * Populate fastest service info.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function populateFastestService(Address $address, Collection $transitTimes): void
    {
        $fastest = $this->findFastestService($transitTimes);

        if ($fastest) {
            $address->fastest_service = $this->sanitizeServiceName($fastest->service_name ?: $fastest->service_type);
            $address->fastest_date = $fastest->delivery_date;
        } else {
            $address->fastest_service = null;
            $address->fastest_date = null;
        }
    }

    /**
     * Populate distance from transit times.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function populateDistance(Address $address, Collection $transitTimes): void
    {
        // Get distance from first transit time that has it
        $withDistance = $transitTimes->first(fn ($tt) => $tt->distance_value !== null);

        if ($withDistance && $withDistance->distance_units === 'MI') {
            $address->distance_miles = $withDistance->distance_value;
        } elseif ($withDistance && $withDistance->distance_units === 'KM') {
            // Convert kilometers to miles
            $address->distance_miles = round($withDistance->distance_value * 0.621371, 2);
        } else {
            $address->distance_miles = null;
        }
    }

    /**
     * Find the fastest service based on delivery date.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function findFastestService(Collection $transitTimes): ?TransitTime
    {
        return $transitTimes
            ->filter(fn (TransitTime $tt) => $tt->delivery_date !== null)
            ->sortBy('delivery_date')
            ->first();
    }

    /**
     * Find services that can deliver by the required date.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     * @return Collection<int, TransitTime>
     */
    protected function findServicesMeetingDeadline(
        Collection $transitTimes,
        CarbonInterface $requiredDate
    ): Collection {
        return $transitTimes->filter(function (TransitTime $transitTime) use ($requiredDate) {
            if (! $transitTime->delivery_date) {
                return false;
            }

            // Service delivers on or before the required date
            return $transitTime->delivery_date->lte($requiredDate);
        });
    }

    /**
     * Find the most economical (cheapest) service from viable options.
     * Uses service type ranking as a proxy for cost.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function findMostEconomicalService(Collection $transitTimes): TransitTime
    {
        return $transitTimes
            ->sortBy(function (TransitTime $tt) {
                // Lower rank = cheaper service
                return self::SERVICE_COST_RANK[$tt->service_type] ?? 100;
            })
            ->first();
    }

    /**
     * Clear all recommendation and calculated fields on an address.
     */
    protected function clearRecommendations(Address $address): Address
    {
        $address->recommended_service = null;
        $address->estimated_delivery_date = null;
        $address->can_meet_required_date = null;
        $address->fastest_service = null;
        $address->fastest_date = null;
        $address->ship_via_service = null;
        $address->ship_via_days = null;
        $address->ship_via_date = null;
        $address->ship_via_meets_deadline = null;
        $address->suggested_service = null;
        $address->suggested_delivery_date = null;
        $address->distance_miles = null;
        $address->save();

        return $address;
    }

    /**
     * Apply BestWay optimization to an address.
     *
     * This finds the most economical shipping service that meets the required delivery date.
     * - Preserves the original ship_via_code in previous_ship_via_code
     * - Updates ship_via_code to the optimized service
     * - Sets bestway_optimized flag to true
     *
     * Returns true if optimization was applied, false otherwise.
     */
    public function applyBestWayOptimization(Address $address): bool
    {
        // Must have a required on-site date to optimize
        if (! $address->required_on_site_date) {
            return false;
        }

        // Get transit times
        $transitTimes = $address->relationLoaded('transitTimes')
            ? $address->transitTimes
            : $address->transitTimes()->get();

        if ($transitTimes->isEmpty()) {
            return false;
        }

        // Find services that meet the deadline
        $viableServices = $this->findServicesMeetingDeadline($transitTimes, $address->required_on_site_date);

        if ($viableServices->isEmpty()) {
            // No service can meet the deadline - keep original
            $address->bestway_optimized = false;
            $address->save();

            return false;
        }

        // Get original ShipViaCode to extract plant/payment/account
        $originalShipViaCode = $address->relationLoaded('shipViaCodeRecord')
            ? $address->shipViaCodeRecord
            : ShipViaCode::lookup($address->ship_via_code);

        $plantId = $originalShipViaCode?->plant_id;
        $paymentType = $originalShipViaCode?->payment_type;
        $accountNumber = $originalShipViaCode?->account_number;

        // Find the most economical service that has a matching ShipViaCode
        $bestServiceCode = null;
        $bestService = null;

        // Sort viable services by cost (cheapest first)
        $sortedServices = $viableServices->sortBy(function ($tt) {
            return self::SERVICE_COST_RANK[$tt->service_type] ?? 100;
        });

        foreach ($sortedServices as $candidateService) {
            // Try to find a matching ShipViaCode for this service type
            $matchingCode = ShipViaCode::findMatchingForBestWay(
                $candidateService->service_type,
                $plantId,
                $paymentType,
                $accountNumber
            );

            if ($matchingCode) {
                $bestService = $candidateService;
                $bestServiceCode = $matchingCode->code;
                break;
            }
        }

        if (! $bestServiceCode || ! $bestService) {
            // No matching ShipViaCode found
            $address->bestway_optimized = false;
            $address->save();

            return false;
        }

        // Check if we're already using the best service
        $currentServiceType = $this->getShipViaServiceType($address);
        $serviceChanged = ($currentServiceType !== $bestService->service_type);

        // Preserve original ship_via_code
        $address->previous_ship_via_code = $address->ship_via_code;

        if ($serviceChanged) {
            // Service needs to be changed
            $address->ship_via_code = $bestServiceCode;
            $address->ship_via_code_id = null; // Clear so it gets re-resolved

            // Recalculate ship_via fields with new service
            $this->resolveShipViaCode($address);
            $shipViaServiceType = $this->getShipViaServiceType($address);
            if ($shipViaServiceType) {
                $this->populateShipViaInfo($address, $transitTimes, $shipViaServiceType);
                if ($address->required_on_site_date) {
                    $this->validateShipViaMeetsDeadline($address, $transitTimes);
                }
            }
        }

        // BestWay analyzed and confirmed/set optimal service
        $address->bestway_optimized = true;
        $address->save();

        return $serviceChanged;
    }

    /**
     * Apply BestWay optimization to multiple addresses in batch.
     *
     * Uses plant_id, payment_type, and account_number from the original ShipViaCode
     * to find a matching code for the new service type.
     *
     * @param  Collection<int, Address>  $addresses
     * @return array{processed: int, optimized: int, already_optimal: int, no_viable_service: int, no_matching_code: int}
     */
    public function applyBestWayOptimizationBatch(Collection $addresses): array
    {
        $processed = 0;
        $optimized = 0;
        $alreadyOptimal = 0;
        $noViableService = 0;
        $noMatchingCode = 0;

        $updates = [];

        foreach ($addresses as $address) {
            $processed++;

            // Must have a required on-site date to optimize
            if (! $address->required_on_site_date) {
                continue;
            }

            // Get transit times
            $transitTimes = $address->relationLoaded('transitTimes')
                ? $address->transitTimes
                : $address->transitTimes()->get();

            if ($transitTimes->isEmpty()) {
                $noViableService++;

                continue;
            }

            // Find services that meet the deadline
            $viableServices = $this->findServicesMeetingDeadline($transitTimes, $address->required_on_site_date);

            if ($viableServices->isEmpty()) {
                $noViableService++;
                $address->bestway_optimized = false;
                $updates[$address->id] = ['bestway_optimized' => false];

                continue;
            }

            // Get original ShipViaCode to extract plant/payment/account
            $originalShipViaCode = $address->relationLoaded('shipViaCodeRecord')
                ? $address->shipViaCodeRecord
                : ShipViaCode::lookup($address->ship_via_code);

            $plantId = $originalShipViaCode?->plant_id;
            $paymentType = $originalShipViaCode?->payment_type;
            $accountNumber = $originalShipViaCode?->account_number;

            // Find the most economical service that has a matching ShipViaCode
            $bestServiceCode = null;
            $bestService = null;

            // Sort viable services by cost (cheapest first)
            $sortedServices = $viableServices->sortBy(function ($tt) {
                return self::SERVICE_COST_RANK[$tt->service_type] ?? 100;
            });

            foreach ($sortedServices as $candidateService) {
                // Try to find a matching ShipViaCode for this service type
                $matchingCode = ShipViaCode::findMatchingForBestWay(
                    $candidateService->service_type,
                    $plantId,
                    $paymentType,
                    $accountNumber
                );

                if ($matchingCode) {
                    $bestService = $candidateService;
                    $bestServiceCode = $matchingCode->code;
                    break;
                }
            }

            if (! $bestServiceCode || ! $bestService) {
                // No matching ShipViaCode found for any viable service
                $noMatchingCode++;
                $address->bestway_optimized = false;
                $updates[$address->id] = ['bestway_optimized' => false];

                continue;
            }

            // Check if we're already using the best service
            $currentServiceType = $this->getShipViaServiceType($address);
            $serviceChanged = ($currentServiceType !== $bestService->service_type);

            if ($serviceChanged) {
                // Service needs to be changed - preserve original and update
                $address->previous_ship_via_code = $address->ship_via_code;
                $address->ship_via_code = $bestServiceCode;
                $address->ship_via_code_id = null;
                $optimized++;

                // Recalculate ship_via fields with new service
                $this->resolveShipViaCode($address);
                $shipViaServiceType = $this->getShipViaServiceType($address);
                if ($shipViaServiceType) {
                    $this->populateShipViaInfo($address, $transitTimes, $shipViaServiceType);
                    if ($address->required_on_site_date) {
                        $this->validateShipViaMeetsDeadline($address, $transitTimes);
                    }
                }
            } else {
                // Already using the best service - no change needed
                $alreadyOptimal++;
                // Set previous to same as current to indicate no change was needed
                $address->previous_ship_via_code = $address->ship_via_code;
            }

            // BestWay analyzed and confirmed/set optimal service - always true when we reach here
            $address->bestway_optimized = true;

            $updates[$address->id] = [
                'previous_ship_via_code' => $address->previous_ship_via_code,
                'ship_via_code' => $address->ship_via_code,
                'ship_via_code_id' => $address->ship_via_code_id,
                'ship_via_service' => $address->ship_via_service,
                'ship_via_days' => $address->ship_via_days,
                'ship_via_date' => $address->ship_via_date,
                'ship_via_meets_deadline' => $address->ship_via_meets_deadline,
                'suggested_service' => $address->suggested_service,
                'suggested_delivery_date' => $address->suggested_delivery_date,
                'bestway_optimized' => true,
            ];
        }

        // Bulk update all addresses
        foreach ($updates as $addressId => $data) {
            Address::where('id', $addressId)->update($data);
        }

        return [
            'processed' => $processed,
            'optimized' => $optimized,
            'already_optimal' => $alreadyOptimal,
            'no_viable_service' => $noViableService,
            'no_matching_code' => $noMatchingCode,
        ];
    }

    /**
     * Get the standard shipping code for a service type.
     */
    protected function getServiceCodeForType(string $serviceType): ?string
    {
        // Map service types back to standard carrier codes
        $serviceTypeToCode = [
            // FedEx Ground services
            'FEDEX_GROUND' => 'FXG',
            'GROUND_HOME_DELIVERY' => 'FXHD',
            'SMART_POST' => 'FXSP',

            // FedEx Express services
            'FEDEX_EXPRESS_SAVER' => 'FXES',
            'FEDEX_2_DAY' => 'FX2D',
            'FEDEX_2_DAY_AM' => 'FX2DAM',
            'STANDARD_OVERNIGHT' => 'FXSO',
            'PRIORITY_OVERNIGHT' => 'FXPO',
            'FIRST_OVERNIGHT' => 'FXFO',

            // FedEx Freight
            'FEDEX_FREIGHT_ECONOMY' => 'FXFE',
            'FEDEX_FREIGHT_PRIORITY' => 'FXFP',

            // FedEx International
            'INTERNATIONAL_ECONOMY' => 'FXIE',
            'INTERNATIONAL_PRIORITY' => 'FXIP',
            'INTERNATIONAL_FIRST' => 'FXIF',
        ];

        return $serviceTypeToCode[$serviceType] ?? null;
    }

    /**
     * Get BestWay optimization explanation for an address.
     */
    public function getBestWayExplanation(Address $address): ?string
    {
        if (! $address->bestway_optimized) {
            return null;
        }

        $previousService = $address->previous_ship_via_code ?? 'none';
        $newService = $address->ship_via_code ?? 'unknown';

        $explanation = "BestWay Optimized: Changed from {$previousService} to {$newService}";

        if ($address->ship_via_service && $address->ship_via_date) {
            $explanation .= " ({$address->ship_via_service} - delivers {$address->ship_via_date->format('M j, Y')})";
        }

        return $explanation;
    }

    /**
     * Get a human-readable explanation of the recommendation.
     */
    public function getRecommendationExplanation(Address $address): ?string
    {
        $explanations = [];

        // Ship Via analysis
        if ($address->ship_via_service) {
            $shipViaInfo = "Selected: {$address->ship_via_service}";

            if ($address->ship_via_date) {
                $shipViaInfo .= " (delivers {$address->ship_via_date->format('M j, Y')})";
            }

            if ($address->ship_via_meets_deadline === false) {
                $shipViaInfo .= ' ⚠️ WILL NOT MEET DEADLINE';

                if ($address->suggested_service) {
                    $shipViaInfo .= " → Suggest: {$address->suggested_service}";
                }
            } elseif ($address->ship_via_meets_deadline === true) {
                $shipViaInfo .= ' ✓ Meets deadline';
            }

            $explanations[] = $shipViaInfo;
        }

        // Recommendation (when no ship via or deadline-based)
        if ($address->required_on_site_date && ! $address->ship_via_service) {
            if ($address->can_meet_required_date === false) {
                $explanation = "No service can deliver by {$address->required_on_site_date->format('M j, Y')}.";

                if ($address->fastest_service) {
                    $explanation .= " Fastest: {$address->fastest_service} (delivers {$address->fastest_date->format('M j, Y')})";
                }

                $explanations[] = $explanation;
            } elseif ($address->recommended_service) {
                $explanations[] = "Recommended: {$address->recommended_service} - delivers {$address->estimated_delivery_date->format('M j, Y')}";
            }
        }

        // Fastest always
        if ($address->fastest_service && ! $address->ship_via_service && ! $address->required_on_site_date) {
            $explanations[] = "Fastest: {$address->fastest_service} (delivers {$address->fastest_date->format('M j, Y')})";
        }

        return ! empty($explanations) ? implode("\n", $explanations) : null;
    }
}
