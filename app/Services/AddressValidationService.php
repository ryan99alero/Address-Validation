<?php

namespace App\Services;

use App\Models\Address;
use App\Models\AddressCandidate;
use App\Models\AddressVariant;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\FedExCarrier;
use App\Services\Carriers\SmartyCarrier;
use App\Services\Carriers\UpsCarrier;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class AddressValidationService
{
    protected bool $useLocalCache = true;

    /**
     * Get a carrier service instance by slug.
     */
    public function getCarrierService(string $slug): CarrierInterface
    {
        $carrier = Carrier::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->createCarrierService($carrier);
    }

    /**
     * Get a carrier service instance by Carrier model.
     */
    public function getCarrierServiceForCarrier(Carrier $carrier): CarrierInterface
    {
        return $this->createCarrierService($carrier);
    }

    /**
     * Create the appropriate carrier service instance.
     */
    protected function createCarrierService(Carrier $carrier): CarrierInterface
    {
        $service = match ($carrier->slug) {
            'ups' => new UpsCarrier,
            'fedex' => new FedExCarrier,
            'smarty' => new SmartyCarrier,
            default => throw new Exception("Unsupported carrier: {$carrier->slug}"),
        };

        return $service->setCarrier($carrier);
    }

    /**
     * Enable or disable local cache lookup.
     */
    public function useLocalCache(bool $enabled = true): self
    {
        $this->useLocalCache = $enabled;

        return $this;
    }

    /**
     * Validate a single address using the specified carrier.
     * Checks local cache first if enabled.
     */
    public function validateAddress(Address $address, string $carrierSlug, bool $checkBoth = false): Address
    {
        $carrier = Carrier::where('slug', $carrierSlug)->where('is_active', true)->firstOrFail();

        // Check both sources: record DB + API candidates, flag needs_review on disagreement.
        if ($checkBoth) {
            return $this->validateBatchCheckingBoth([$address], $carrier)[0];
        }

        // Try local cache first
        if ($this->useLocalCache) {
            $cachedResult = $this->lookupLocalCache($address);
            if ($cachedResult) {
                Log::info('Address validated from local cache', [
                    'address_id' => $address->id,
                    'input_address' => $address->input_address_1,
                ]);

                $address->applyValidationResult($cachedResult, $carrier->id, Address::SOURCE_LOCAL_CACHE);

                return $address->fresh();
            }
        }

        // Fall back to carrier API
        $service = $this->createCarrierService($carrier);
        $validatedAddress = $service->validateAddress($address);

        // Update source to carrier API
        $validatedAddress->update(['validation_source' => $this->sourceForSlug($carrierSlug)]);

        return $validatedAddress;
    }

    /**
     * Look up address in local correction cache.
     *
     * @return array<string, mixed>|null Validation result array or null if not found
     */
    protected function lookupLocalCache(Address $address): ?array
    {
        if (empty($address->input_address_1) || empty($address->input_postal)) {
            return null;
        }

        $correctedAddress = AddressVariant::lookup(
            $address->input_address_1,
            $address->input_city,
            $address->input_state,
            $address->input_postal,
            $address->input_country ?? 'US'
        );

        if (! $correctedAddress) {
            return null;
        }

        return $this->correctedToResult($correctedAddress);
    }

    /**
     * Convert a cached CorrectedAddress into the standard validation result shape
     * consumed by Address::applyValidationResult().
     *
     * @return array<string, mixed>
     */
    protected function correctedToResult(CorrectedAddress $correctedAddress): array
    {
        return [
            'corrected_address_line_1' => $correctedAddress->address_1,
            'corrected_address_line_2' => $correctedAddress->address_2,
            'corrected_city' => $correctedAddress->city,
            'corrected_state' => $correctedAddress->state,
            'corrected_postal_code' => $correctedAddress->postal,
            'corrected_postal_code_ext' => $correctedAddress->postal_ext,
            'corrected_country_code' => $correctedAddress->country,
            'validation_status' => 'valid',
            'is_residential' => $correctedAddress->is_residential,
            'classification' => $correctedAddress->is_residential ? 'residential' : 'commercial',
            'confidence_score' => 100.0, // Local cache is from actual carrier corrections
        ];
    }

    /**
     * Validate multiple addresses using the specified carrier.
     *
     * Checks the local invoice-derived correction cache FIRST (one query for the
     * whole batch); only addresses with no local match are sent to the carrier API.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    public function validateBatch(array $addresses, string $carrierSlug, bool $checkBoth = false): array
    {
        $carrier = Carrier::where('slug', $carrierSlug)->where('is_active', true)->firstOrFail();

        if ($checkBoth) {
            ksort($addresses);

            return array_values($this->validateBatchCheckingBoth($addresses, $carrier));
        }

        $results = [];
        $misses = $addresses;

        if ($this->useLocalCache) {
            [$results, $misses] = $this->partitionByCache($addresses, $carrier);
        }

        // Anything not found locally goes to the carrier API.
        if (! empty($misses)) {
            $service = $this->createCarrierService($carrier);
            $apiResults = $service->validateBatch(array_values($misses));

            foreach (array_keys($misses) as $i => $key) {
                $results[$key] = $apiResults[$i] ?? $misses[$key];
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Map an engine key to its ordered carrier pipeline. A single carrier slug
     * yields a one-element pipeline (unchanged behavior); a chain like 'fedex_ups'
     * yields ['fedex', 'ups'].
     *
     * @return array<int, string>
     */
    public function enginePipeline(string $engine): array
    {
        return match ($engine) {
            'fedex_ups' => ['fedex', 'ups'],
            'ups_fedex' => ['ups', 'fedex'],
            default => [$engine],
        };
    }

    /**
     * Validate a batch using an engine that may be a single carrier slug
     * ('fedex', 'ups') or a fallback chain ('fedex_ups', 'ups_fedex').
     *
     * Single carrier → identical to validateBatch() (honors $checkBoth). Chain →
     * cache-first (shared), then each carrier in order; the first carrier that
     * returns a usable (STATUS_VALID) result claims the address and it is not sent
     * to later carriers. This is a fallback chain, NOT a DB-vs-API reconcile, so
     * $checkBoth does not apply to chains.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    public function validateBatchWithEngine(array $addresses, string $engine, bool $checkBoth = false): array
    {
        $pipeline = $this->enginePipeline($engine);

        if (count($pipeline) === 1) {
            return $this->validateBatch($addresses, $pipeline[0], $checkBoth);
        }

        $carriers = array_map(
            fn (string $slug): Carrier => Carrier::where('slug', $slug)->where('is_active', true)->firstOrFail(),
            $pipeline
        );

        $results = [];
        $misses = $addresses;

        // Cache-first (carrier-agnostic); stamp the primary carrier on hits.
        if ($this->useLocalCache) {
            [$hits, $misses] = $this->partitionByCache($misses, $carriers[0]);
            $results += $hits;
        }

        // Run each carrier in order; a usable result removes the address from the pool.
        foreach ($carriers as $carrier) {
            if (empty($misses)) {
                break;
            }

            $service = $this->createCarrierService($carrier);
            $apiResults = array_values($service->validateBatch(array_values($misses)));
            $source = $this->sourceForSlug($carrier->slug);

            $stillMiss = [];
            foreach (array_keys($misses) as $i => $key) {
                $result = $apiResults[$i] ?? $misses[$key];

                if ($result->validation_status === Address::STATUS_VALID && $result->output_address_1 !== null) {
                    $result->update(['validation_source' => $source]);
                    $results[$key] = $result;
                } else {
                    $stillMiss[$key] = $misses[$key];
                }
            }

            $misses = $stillMiss;
        }

        // Anything still unresolved keeps whatever the last carrier left on it.
        foreach ($misses as $key => $address) {
            $results[$key] = $address->fresh() ?? $address;
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Resolve the batch's local-cache hits (applying them to the addresses) and
     * return [hits, misses] keyed by the original array keys.
     *
     * @param  array<Address>  $addresses
     * @return array{0: array<int|string, Address>, 1: array<int|string, Address>}
     */
    protected function partitionByCache(array $addresses, Carrier $carrier): array
    {
        $lookupInput = collect($addresses)
            ->filter(fn (Address $address): bool => ! empty($address->input_address_1) && ! empty($address->input_postal))
            ->map(fn (Address $address): array => [
                'address_1' => $address->input_address_1,
                'city' => $address->input_city,
                'state' => $address->input_state,
                'postal' => $address->input_postal,
                'country' => $address->input_country ?? 'US',
            ]);

        $lookup = AddressVariant::lookupBatch($lookupInput);

        $hits = [];
        $misses = [];
        foreach ($addresses as $key => $address) {
            if (isset($lookup['hits'][$key])) {
                $address->applyValidationResult(
                    $this->correctedToResult($lookup['hits'][$key]),
                    $carrier->id,
                    Address::SOURCE_LOCAL_CACHE
                );
                $hits[$key] = $address;
            } else {
                $misses[$key] = $address;
            }
        }

        return [$hits, $misses];
    }

    /**
     * Map a carrier slug to its Address validation-source constant.
     */
    protected function sourceForSlug(string $slug): string
    {
        return match ($slug) {
            'ups' => Address::SOURCE_UPS_API,
            'fedex' => Address::SOURCE_FEDEX_API,
            'usps' => Address::SOURCE_USPS_API,
            'smarty' => Address::SOURCE_SMARTY_API,
            default => Address::SOURCE_UPS_API,
        };
    }

    /**
     * Validate each address against BOTH the local invoice cache and the carrier
     * API, recording a candidate per source. On agreement the address is accepted
     * (invoice data is authoritative); on disagreement it is flagged needs_review
     * with both candidates retained for a manual pick.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    protected function validateBatchCheckingBoth(array $addresses, Carrier $carrier): array
    {
        // Local cache lookup for the whole set (one query).
        $lookupInput = collect($addresses)
            ->filter(fn (Address $address): bool => ! empty($address->input_address_1) && ! empty($address->input_postal))
            ->map(fn (Address $address): array => [
                'address_1' => $address->input_address_1,
                'city' => $address->input_city,
                'state' => $address->input_state,
                'postal' => $address->input_postal,
                'country' => $address->input_country ?? 'US',
            ]);

        $lookup = AddressVariant::lookupBatch($lookupInput);

        // Carrier API for the whole set (writes output_* on each address).
        $service = $this->createCarrierService($carrier);
        $apiResults = collect($service->validateBatch($addresses))->keyBy('id');

        $results = [];
        foreach ($addresses as $key => $address) {
            $apiAddress = $apiResults->get($address->id, $address);
            $dbCorrected = $lookup['hits'][$key] ?? null;

            $results[$key] = $this->reconcileSources($address, $apiAddress, $dbCorrected, $carrier);
        }

        return $results;
    }

    /**
     * Reconcile a single address's local-cache hit and carrier-API result.
     */
    protected function reconcileSources(Address $address, Address $apiAddress, ?CorrectedAddress $dbCorrected, Carrier $carrier): Address
    {
        // Clear any candidates from a prior run for a clean state.
        $address->candidates()->delete();

        $apiHasResult = $apiAddress->output_address_1 !== null && $apiAddress->validation_status === Address::STATUS_VALID;

        if ($dbCorrected && $apiHasResult) {
            $dbCandidate = $this->recordDbCandidate($address, $dbCorrected);
            $apiCandidate = $this->recordApiCandidate($address, $apiAddress, $carrier);

            if ($this->candidatesAgree($dbCandidate, $apiCandidate)) {
                // Sources agree: accept invoice data as authoritative, drop candidates.
                $address->applyValidationResult($this->correctedToResult($dbCorrected), $carrier->id, Address::SOURCE_LOCAL_CACHE);
                $address->candidates()->delete();
            } else {
                // Sources disagree: flag for manual pick, keep both candidates.
                $address->update([
                    'output_address_1' => null,
                    'output_address_2' => null,
                    'output_city' => null,
                    'output_state' => null,
                    'output_postal' => null,
                    'output_postal_ext' => null,
                    'output_country' => null,
                    'validation_status' => Address::STATUS_NEEDS_REVIEW,
                    'validation_source' => null,
                    'validated_by_carrier_id' => $carrier->id,
                    'validated_at' => now(),
                ]);
            }
        } elseif ($dbCorrected) {
            // Only the invoice cache had a match.
            $address->applyValidationResult($this->correctedToResult($dbCorrected), $carrier->id, Address::SOURCE_LOCAL_CACHE);
        }
        // Else: only the API (or neither) produced a result — already persisted by the carrier service.

        return $address->fresh() ?? $address;
    }

    protected function recordDbCandidate(Address $address, CorrectedAddress $corrected): AddressCandidate
    {
        return $address->candidates()->create([
            'source' => AddressCandidate::SOURCE_INVOICE_DB,
            'address_1' => $corrected->address_1,
            'address_2' => $corrected->address_2,
            'city' => $corrected->city,
            'state' => $corrected->state,
            'postal' => $corrected->postal,
            'postal_ext' => $corrected->postal_ext,
            'country' => $corrected->country,
            'is_residential' => $corrected->is_residential,
            'classification' => $corrected->is_residential ? 'residential' : 'commercial',
            'confidence_score' => 100.0,
            'corrected_address_id' => $corrected->id,
        ]);
    }

    protected function recordApiCandidate(Address $address, Address $apiAddress, Carrier $carrier): AddressCandidate
    {
        return $address->candidates()->create([
            'source' => match ($carrier->slug) {
                'fedex' => AddressCandidate::SOURCE_FEDEX_API,
                'ups' => AddressCandidate::SOURCE_UPS_API,
                'usps' => AddressCandidate::SOURCE_USPS_API,
                default => AddressCandidate::SOURCE_MANUAL,
            },
            'address_1' => $apiAddress->output_address_1,
            'address_2' => $apiAddress->output_address_2,
            'city' => $apiAddress->output_city,
            'state' => $apiAddress->output_state,
            'postal' => $apiAddress->output_postal,
            'postal_ext' => $apiAddress->output_postal_ext,
            'country' => $apiAddress->output_country,
            'is_residential' => $apiAddress->is_residential,
            'classification' => $apiAddress->classification,
            'confidence_score' => $apiAddress->confidence_score,
            'carrier_id' => $carrier->id,
        ]);
    }

    /**
     * Whether two candidates resolve to the same address (normalized comparison).
     */
    protected function candidatesAgree(AddressCandidate $a, AddressCandidate $b): bool
    {
        return CorrectedAddress::normalize($a->address_1) === CorrectedAddress::normalize($b->address_1)
            && CorrectedAddress::normalize($a->city) === CorrectedAddress::normalize($b->city)
            && CorrectedAddress::normalize($a->state) === CorrectedAddress::normalize($b->state)
            && CorrectedAddress::normalizePostal($a->postal) === CorrectedAddress::normalizePostal($b->postal);
    }

    /**
     * Test connection for a specific carrier.
     */
    public function testConnection(string $carrierSlug): bool
    {
        $service = $this->getCarrierService($carrierSlug);

        return $service->testConnection();
    }

    /**
     * Get all active carriers.
     *
     * @return Collection<int, Carrier>
     */
    public function getActiveCarriers()
    {
        return Carrier::active()->get();
    }

    /**
     * Validate multiple addresses concurrently.
     *
     * @param  array<Address>  $addresses
     * @return array<array{address_id: int, success: bool, address: ?Address, error: ?string}>
     */
    public function validateAddressesConcurrently(array $addresses, string $carrierSlug): array
    {
        $service = $this->getCarrierService($carrierSlug);

        // Use concurrent method if available on the carrier
        if (method_exists($service, 'validateAddressesConcurrently')) {
            return $service->validateAddressesConcurrently($addresses);
        }

        // Fallback to sequential processing
        $results = [];
        foreach ($addresses as $address) {
            try {
                $validatedAddress = $service->validateAddress($address);
                $results[] = [
                    'address_id' => $address->id,
                    'success' => true,
                    'address' => $validatedAddress,
                    'error' => null,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'address_id' => $address->id,
                    'success' => false,
                    'address' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
