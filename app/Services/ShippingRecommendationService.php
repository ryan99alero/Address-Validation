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

            if ($address->ship_via_service_name) {
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
        $address->fastest_delivery_date = null;
        $address->ship_via_service_name = null;
        $address->ship_via_transit_days = null;
        $address->ship_via_delivery_date = null;
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
            $address->ship_via_service_name = $transitTime->service_name
                ?: (ShipViaCode::SERVICE_TYPE_LABELS[$serviceType] ?? $serviceType);
            $address->ship_via_transit_days = $transitTime->transit_range;
            $address->ship_via_delivery_date = $transitTime->delivery_date;
        } else {
            // Service type exists but no transit time data for it
            $address->ship_via_service_name = ShipViaCode::SERVICE_TYPE_LABELS[$serviceType] ?? $serviceType;
            $address->ship_via_transit_days = null;
            $address->ship_via_delivery_date = null;
        }
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
        if ($address->ship_via_delivery_date) {
            $meetsDeadline = $address->ship_via_delivery_date->lte($requiredDate);
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

        $address->suggested_service = $suggested->service_name ?: $suggested->service_type;
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
            $address->recommended_service = $recommended->service_name ?: $recommended->service_type;
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
            $address->fastest_service = $fastest->service_name ?: $fastest->service_type;
            $address->fastest_delivery_date = $fastest->delivery_date;
        } else {
            $address->fastest_service = null;
            $address->fastest_delivery_date = null;
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
        $address->fastest_delivery_date = null;
        $address->ship_via_service_name = null;
        $address->ship_via_transit_days = null;
        $address->ship_via_delivery_date = null;
        $address->ship_via_meets_deadline = null;
        $address->suggested_service = null;
        $address->suggested_delivery_date = null;
        $address->distance_miles = null;
        $address->save();

        return $address;
    }

    /**
     * Get a human-readable explanation of the recommendation.
     */
    public function getRecommendationExplanation(Address $address): ?string
    {
        $explanations = [];

        // Ship Via analysis
        if ($address->ship_via_service_name) {
            $shipViaInfo = "Selected: {$address->ship_via_service_name}";

            if ($address->ship_via_delivery_date) {
                $shipViaInfo .= " (delivers {$address->ship_via_delivery_date->format('M j, Y')})";
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
        if ($address->required_on_site_date && ! $address->ship_via_service_name) {
            if ($address->can_meet_required_date === false) {
                $explanation = "No service can deliver by {$address->required_on_site_date->format('M j, Y')}.";

                if ($address->fastest_service) {
                    $explanation .= " Fastest: {$address->fastest_service} (delivers {$address->fastest_delivery_date->format('M j, Y')})";
                }

                $explanations[] = $explanation;
            } elseif ($address->recommended_service) {
                $explanations[] = "Recommended: {$address->recommended_service} - delivers {$address->estimated_delivery_date->format('M j, Y')}";
            }
        }

        // Fastest always
        if ($address->fastest_service && ! $address->ship_via_service_name && ! $address->required_on_site_date) {
            $explanations[] = "Fastest: {$address->fastest_service} (delivers {$address->fastest_delivery_date->format('M j, Y')})";
        }

        return ! empty($explanations) ? implode("\n", $explanations) : null;
    }
}
