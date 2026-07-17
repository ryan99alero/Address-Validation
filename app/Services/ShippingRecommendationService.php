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

        // UPS domestic ladder (short codes, cheapest → dearest). Key spaces are disjoint from
        // FedEx and a batch's transit rows are single-carrier, so one flat map stays correct.
        'SP' => 8,    // SurePost (below Ground)
        'GND' => 10,  // Ground
        'STD' => 12,  // Standard (to CA/MX)
        '3DS' => 20,  // 3 Day Select
        '2DA' => 30,  // 2nd Day Air
        '2DM' => 35,  // 2nd Day Air A.M.
        'NDS' => 40,  // Next Day Air Saver
        'NDA' => 50,  // Next Day Air
        'NDM' => 60,  // Next Day Air Early
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
     * Just-In-Time service selection: the CHEAPEST service that can arrive on/before
     * the required on-site date shipping on/after the earliest ship date, AND that
     * carries a ship-via code on the SAME plant + payment + account as the original.
     * We never jump plant (a physical site) or account (billing, sometimes client-
     * owned). Returns null when nothing on the same plant+account can arrive in time.
     *
     * Ship date is derived by inverting each service's business-day transit from the
     * required date (ship as late as possible), so arrival lands on the required date.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     * @return array{transit: TransitTime, service_type: string, code: string, ship_date: CarbonInterface}|null
     */
    protected function selectJitService(Address $address, Collection $transitTimes, ?string $plantOverride, ?int $accountOverride = null): ?array
    {
        $required = $address->required_on_site_date?->startOfDay();
        if (! $required || $transitTimes->isEmpty()) {
            return null;
        }

        // Earliest we can ship: the requested ship date, but never in the past.
        $floor = $address->requested_ship_date?->startOfDay() ?? now()->startOfDay();
        if ($floor->lt(now()->startOfDay())) {
            $floor = now()->startOfDay();
        }

        $original = $address->relationLoaded('shipViaCodeRecord')
            ? $address->shipViaCodeRecord
            : ShipViaCode::lookup($address->ship_via_code);
        // The payer is derived from the original code's billed account (owner), so load them.
        $original?->loadMissing(['carrierAccount', 'thirdPartyAccount']);

        // A batch ship account fully supersedes the row's ShipVia (scenarios 1 & 2): match only
        // that account's codes on the batch plant, regardless of whether the file ShipVia is
        // present, different, or blank. Otherwise (scenario 3) fall back to the row's own code.
        if ($accountOverride !== null) {
            $plantId = $plantOverride;
        } else {
            // No account chosen AND no resolvable ShipVia → no basis to bill; don't optimize.
            if ($original === null) {
                return null;
            }
            $plantId = $plantOverride ?: $original->plant_id;
        }

        $candidates = $transitTimes
            ->map(function (TransitTime $t) use ($required, $floor, $plantId, $original, $accountOverride) {
                // Duration is measured from the ship date FedEx quoted against (stored on
                // the transit row, holiday/weekend-aware), then inverted from the required
                // date below to ship as late as possible.
                $duration = $t->transitBusinessDays();
                if ($duration === null) {
                    return null;
                }

                $shipDate = $required->copy()->subWeekdays($duration);
                if ($shipDate->lt($floor)) {
                    return null; // would have to ship before the earliest ship date
                }

                $code = ShipViaCode::findMatchingForBestWay($t->service_type, $plantId, $original, $accountOverride);
                if (! $code) {
                    return null; // no code on the same plant + owner/account — never jump payers
                }

                return ['transit' => $t, 'service_type' => $t->service_type, 'code' => $code->code, 'ship_date' => $shipDate];
            })
            ->filter();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortBy(fn (array $c): array => [
                self::SERVICE_COST_RANK[$c['service_type']] ?? 100,
                -$c['ship_date']->timestamp,
            ])
            ->first();
    }

    /**
     * Apply the JIT selection to one address (no save). Sets the ship-via code, the
     * latest ship date (recommended_ship_date), and ship-via service/days/arrival.
     * When nothing on the same plant+account can arrive in time it flags the address
     * (bestway_optimized=false, ship_via_meets_deadline=false) instead of silently
     * leaving it on a service that misses the date.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     * @return 'optimized'|'already_optimal'|'no_service'
     */
    protected function applyJitToAddress(Address $address, Collection $transitTimes, ?string $plantOverride, ?int $accountOverride = null): string
    {
        $jit = $this->selectJitService($address, $transitTimes, $plantOverride, $accountOverride);

        if (! $jit) {
            $address->bestway_optimized = false;
            $address->ship_via_meets_deadline = false;
            $address->recommended_ship_date = null;
            $address->recommended_ship_service = null;
            $address->bestway_service_type = null;
            $address->arrival_verified = null;

            return 'no_service';
        }

        $changed = $jit['code'] !== $address->ship_via_code;
        $address->previous_ship_via_code = $address->ship_via_code;
        $address->ship_via_code = $jit['code'];
        $address->ship_via_code_id = null;
        $this->resolveShipViaCode($address);

        // Service label from the chosen transit option; arrival = the required date
        // (ship date was computed as required − transit), days = business-day transit.
        $this->populateShipViaInfo($address, $transitTimes, $jit['service_type']);
        $address->ship_via_days = $jit['transit']->transitBusinessDays();
        $address->ship_via_date = $address->required_on_site_date;
        $address->ship_via_meets_deadline = true;
        $address->recommended_ship_date = $jit['ship_date'];
        $address->recommended_ship_service = $address->ship_via_service;
        $address->bestway_service_type = $jit['service_type'];
        // Not yet FedEx-confirmed; the arrival above is the inferred required date. A
        // reverse-validation pass (when enabled) re-quotes the ship date and sets this.
        $address->arrival_verified = null;
        $address->suggested_service = null;
        $address->suggested_delivery_date = null;
        $address->bestway_optimized = true;

        return $changed ? 'optimized' : 'already_optimal';
    }

    /**
     * Apply BestWay (JIT) optimization to an address.
     *
     * Returns true if the ship-via service was changed, false otherwise.
     */
    public function applyBestWayOptimization(Address $address, ?string $plantOverride = null): bool
    {
        $transitTimes = $address->relationLoaded('transitTimes')
            ? $address->transitTimes
            : $address->transitTimes()->get();

        if (! $address->required_on_site_date || $transitTimes->isEmpty()) {
            $address->bestway_optimized = false;
            $address->save();

            return false;
        }

        $outcome = $this->applyJitToAddress($address, $transitTimes, $plantOverride);
        $address->save();

        return $outcome === 'optimized';
    }

    /**
     * Reverse-validate one BestWay-optimized address with a SECOND FedEx call: re-quote the
     * chosen service at the computed ship date and confirm FedEx's committed delivery is on
     * time (holiday/weekend-aware) rather than trusting the inverted estimate. Sets the real
     * ship_via_date + arrival_verified; flags a slip (never hides a late arrival). Only runs
     * for FUTURE-dated optimized shipments — a today-dated ship already reflects the quote.
     *
     * @return 'confirmed'|'slipped'|'unverifiable'|'skipped'
     */
    public function reverseValidateArrival(Address $address, FedExServiceAvailabilityService $fedex): string
    {
        if (! $address->bestway_optimized || ! $address->bestway_service_type
            || ! $address->recommended_ship_date || ! $address->required_on_site_date) {
            return 'skipped';
        }

        $shipDate = $address->recommended_ship_date->startOfDay();
        if ($shipDate->lte(now()->startOfDay())) {
            return 'skipped'; // today/past ship date already reflects the live quote
        }

        $delivery = $fedex->getDeliveryDateForShipDate($address, $address->bestway_service_type, $shipDate);
        if (! $delivery) {
            $address->arrival_verified = null;
            $address->save();

            return 'unverifiable';
        }

        $onTime = $delivery->startOfDay()->lte($address->required_on_site_date->startOfDay());

        $address->ship_via_date = $delivery;   // FedEx's real committed arrival — the honest date
        $address->arrival_verified = $onTime;
        if (! $onTime) {
            $address->ship_via_meets_deadline = false; // holiday/weekend pushed it late — flag it
        }
        $address->save();

        return $onTime ? 'confirmed' : 'slipped';
    }

    /**
     * Reverse-validate a batch of addresses (one FedEx call each). Sequential — each is a
     * distinct ship date, so the calls can't be pooled.
     *
     * @param  Collection<int, Address>  $addresses
     * @return array{checked: int, confirmed: int, slipped: int, unverifiable: int}
     */
    public function reverseValidateArrivalBatch(Collection $addresses, FedExServiceAvailabilityService $fedex): array
    {
        $counts = ['checked' => 0, 'confirmed' => 0, 'slipped' => 0, 'unverifiable' => 0];

        foreach ($addresses as $address) {
            $outcome = $this->reverseValidateArrival($address, $fedex);
            if ($outcome === 'skipped') {
                continue;
            }
            $counts['checked']++;
            $counts[$outcome]++;
        }

        return $counts;
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
    public function applyBestWayOptimizationBatch(Collection $addresses, ?string $plantOverride = null, ?int $accountOverride = null): array
    {
        $counts = ['processed' => 0, 'optimized' => 0, 'already_optimal' => 0, 'no_viable_service' => 0, 'no_matching_code' => 0];
        $updates = [];

        foreach ($addresses as $address) {
            $counts['processed']++;

            $transitTimes = $address->relationLoaded('transitTimes')
                ? $address->transitTimes
                : $address->transitTimes()->get();

            if (! $address->required_on_site_date || $transitTimes->isEmpty()) {
                $counts['no_viable_service']++;

                continue;
            }

            $outcome = $this->applyJitToAddress($address, $transitTimes, $plantOverride, $accountOverride);
            match ($outcome) {
                'optimized' => $counts['optimized']++,
                'already_optimal' => $counts['already_optimal']++,
                'no_service' => $counts['no_matching_code']++,
            };

            $updates[$address->id] = [
                'previous_ship_via_code' => $address->previous_ship_via_code,
                'ship_via_code' => $address->ship_via_code,
                'ship_via_code_id' => $address->ship_via_code_id,
                'ship_via_service' => $address->ship_via_service,
                'ship_via_days' => $address->ship_via_days,
                'ship_via_date' => $address->ship_via_date,
                'ship_via_meets_deadline' => $address->ship_via_meets_deadline,
                'recommended_ship_date' => $address->recommended_ship_date,
                'recommended_ship_service' => $address->recommended_ship_service,
                'suggested_service' => $address->suggested_service,
                'suggested_delivery_date' => $address->suggested_delivery_date,
                'bestway_optimized' => $address->bestway_optimized,
            ];
        }

        foreach ($updates as $addressId => $data) {
            Address::where('id', $addressId)->update($data);
        }

        return $counts;
    }

    /**
     * Reverse scheduling ("arrive on the exact date"): for an address with a
     * required on-site date, work backward to the LATEST ship date and CHEAPEST
     * service that still arrives on time.
     *
     * Approach: each service's worst-case transit duration (business days) is
     * inverted from the required date to a latest ship date. The cheapest service
     * whose latest ship date is not already in the past (>= $floor) wins. Sets
     * recommended_ship_date + recommended_ship_service.
     *
     * Limitations (honest): duration is business-day based and holiday-naive, and
     * taken from a single anchor probe — there is no live re-probe at the computed
     * ship date. Good for planning; not a booking guarantee.
     */
    public function applyReverseSchedule(Address $address, ?CarbonInterface $floor = null): bool
    {
        $transitTimes = $address->relationLoaded('transitTimes')
            ? $address->transitTimes
            : $address->transitTimes()->get();

        $changed = $this->computeReverseSchedule($address, $transitTimes, $floor);
        $address->save();

        return $changed;
    }

    /**
     * Reverse-schedule many addresses with a single bulk update.
     *
     * @param  Collection<int, Address>  $addresses
     * @return array{processed: int, scheduled: int, cannot_meet: int}
     */
    public function applyReverseScheduleBatch(Collection $addresses, ?CarbonInterface $floor = null): array
    {
        $processed = 0;
        $scheduled = 0;
        $cannotMeet = 0;
        $updates = [];

        foreach ($addresses as $address) {
            $processed++;

            $transitTimes = $address->relationLoaded('transitTimes')
                ? $address->transitTimes
                : $address->transitTimes()->get();

            $this->computeReverseSchedule($address, $transitTimes, $floor);

            if ($address->recommended_ship_date) {
                $scheduled++;
            } elseif ($address->required_on_site_date) {
                $cannotMeet++;
            }

            if ($address->isDirty(['recommended_ship_date', 'recommended_ship_service'])) {
                $updates[$address->id] = [
                    'recommended_ship_date' => $address->recommended_ship_date,
                    'recommended_ship_service' => $address->recommended_ship_service,
                ];
            }
        }

        foreach ($updates as $addressId => $data) {
            Address::where('id', $addressId)->update($data);
        }

        return ['processed' => $processed, 'scheduled' => $scheduled, 'cannot_meet' => $cannotMeet];
    }

    /**
     * Core reverse-schedule computation (no save). Returns true when a ship date
     * was set. Clears the fields when there is no required date / no viable service.
     *
     * @param  Collection<int, TransitTime>  $transitTimes
     */
    protected function computeReverseSchedule(Address $address, Collection $transitTimes, ?CarbonInterface $floor = null): bool
    {
        $address->recommended_ship_date = null;
        $address->recommended_ship_service = null;

        if (! $address->required_on_site_date || $transitTimes->isEmpty()) {
            return false;
        }

        $required = $address->required_on_site_date->startOfDay();
        $floor = ($floor ?? now())->startOfDay();

        // Candidate = [service, latestShipDate], keeping only services that can
        // still be shipped on or after the floor and arrive by the required date.
        $candidates = $transitTimes
            ->map(function (TransitTime $tt) use ($required) {
                $duration = $tt->transitBusinessDays();
                if ($duration === null) {
                    return null;
                }

                return [
                    'tt' => $tt,
                    'ship_date' => $required->copy()->subWeekdays($duration),
                ];
            })
            ->filter()
            ->filter(fn (array $c): bool => $c['ship_date']->gte($floor));

        if ($candidates->isEmpty()) {
            return false;
        }

        // Cheapest service among the viable candidates (ties → latest ship date).
        $best = $candidates
            ->sortBy(fn (array $c): array => [
                self::SERVICE_COST_RANK[$c['tt']->service_type] ?? 100,
                -$c['ship_date']->timestamp,
            ])
            ->first();

        $address->recommended_ship_date = $best['ship_date'];
        $address->recommended_ship_service = $this->sanitizeServiceName(
            $best['tt']->service_name ?: $best['tt']->service_type
        );

        return true;
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
