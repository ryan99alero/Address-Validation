<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

abstract class AbstractCarrier implements CarrierInterface
{
    protected Carrier $carrier;

    protected ?string $accessToken = null;

    public function setCarrier(Carrier $carrier): self
    {
        $this->carrier = $carrier;

        return $this;
    }

    /**
     * Validate multiple addresses in batch.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
     */
    public function validateBatch(array $addresses): array
    {
        $corrections = [];

        foreach ($addresses as $address) {
            try {
                $corrections[] = $this->validateAddress($address);
            } catch (Exception $e) {
                // Create a failed correction record
                $corrections[] = $this->createFailedCorrection($address, $e->getMessage());
            }
        }

        return $corrections;
    }

    /**
     * Get or refresh the OAuth access token.
     */
    protected function getAccessToken(): string
    {
        $cacheKey = "carrier_token_{$this->carrier->id}";

        // Check cache first
        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        // Fetch new token
        $token = $this->fetchAccessToken();

        // Cache for 55 minutes (tokens usually expire in 60 minutes)
        Cache::put($cacheKey, $token, now()->addMinutes(55));

        return $token;
    }

    /**
     * Fetch a new access token from the carrier.
     */
    abstract protected function fetchAccessToken(): string;

    /**
     * Get the HTTP client configured for this carrier.
     */
    protected function getHttpClient(): PendingRequest
    {
        return Http::timeout($this->carrier->timeout_seconds)
            ->withToken($this->getAccessToken())
            ->acceptJson();
    }

    /**
     * Create a failed correction record.
     */
    protected function createFailedCorrection(Address $address, string $errorMessage): AddressCorrection
    {
        return new AddressCorrection([
            'address_id' => $address->id,
            'carrier_id' => $this->carrier->id,
            'validation_status' => AddressCorrection::STATUS_INVALID,
            'raw_response' => ['error' => $errorMessage],
            'validated_at' => now(),
        ]);
    }

    /**
     * Clear the cached token (useful when token is invalid).
     */
    protected function clearTokenCache(): void
    {
        Cache::forget("carrier_token_{$this->carrier->id}");
    }

    /**
     * Mark the carrier as connected.
     */
    protected function markConnected(): void
    {
        $this->carrier->markConnected();
    }

    /**
     * Mark the carrier as having an error.
     */
    protected function markError(string $message): void
    {
        $this->carrier->markError($message);
    }
}
