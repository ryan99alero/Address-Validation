<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\Carrier;
use Closure;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
     * Normalize a US state to its 2-letter USPS code for carrier requests. FedEx 400s on a full name
     * ("Kansas"); UPS tolerates it — normalizing everywhere keeps single AND batch validation consistent
     * no matter how the state was typed (Kansas / KANSAS / ks / KS). A 2-letter or unrecognized value
     * passes through unchanged.
     */
    protected function normalizeStateCode(?string $state): string
    {
        $state = trim((string) $state);
        if (strlen($state) === 2) {
            return strtoupper($state);
        }

        return self::STATE_CODES[strtoupper($state)] ?? $state;
    }

    /** USPS full state/territory name → 2-letter code. */
    protected const STATE_CODES = [
        'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR', 'CALIFORNIA' => 'CA',
        'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE', 'DISTRICT OF COLUMBIA' => 'DC',
        'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI', 'IDAHO' => 'ID', 'ILLINOIS' => 'IL',
        'INDIANA' => 'IN', 'IOWA' => 'IA', 'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA',
        'MAINE' => 'ME', 'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE', 'NEVADA' => 'NV',
        'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM', 'NEW YORK' => 'NY',
        'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH', 'OKLAHOMA' => 'OK', 'OREGON' => 'OR',
        'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD',
        'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA',
        'WASHINGTON' => 'WA', 'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        'PUERTO RICO' => 'PR', 'GUAM' => 'GU', 'VIRGIN ISLANDS' => 'VI', 'AMERICAN SAMOA' => 'AS',
        'NORTHERN MARIANA ISLANDS' => 'MP',
    ];

    /**
     * Get the chunk size for batch processing.
     */
    protected function getChunkSize(): int
    {
        return $this->carrier->chunk_size ?? 100;
    }

    /**
     * Get the number of concurrent requests.
     */
    protected function getConcurrentRequests(): int
    {
        return $this->carrier->concurrent_requests ?? 10;
    }

    /**
     * Get the rate limit per minute (null = unlimited).
     */
    protected function getRateLimitPerMinute(): ?int
    {
        return $this->carrier->rate_limit_per_minute;
    }

    /**
     * Check if the carrier supports native batch API.
     */
    protected function supportsNativeBatch(): bool
    {
        return $this->carrier->supports_native_batch ?? false;
    }

    /**
     * Get the native batch size limit.
     */
    protected function getNativeBatchSize(): int
    {
        return $this->carrier->native_batch_size ?? 100;
    }

    /**
     * Validate multiple addresses in batch.
     * Uses carrier settings for chunk size and concurrency.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    public function validateBatch(array $addresses): array
    {
        $results = [];

        foreach ($addresses as $address) {
            try {
                $results[] = $this->validateAddress($address);
            } catch (Exception $e) {
                // Mark the address as failed
                $results[] = $this->markAddressFailed($address, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Process addresses concurrently using HTTP pool.
     * This is used by carriers that don't support native batch (like UPS).
     *
     * @param  array<Address>  $addresses
     * @param  Closure  $requestBuilder  Function that builds the request: fn(Pool $pool, Address $address, int $index) => $pool->as($index)->...
     * @param  Closure  $responseParser  Function that parses the response: fn(Address $address, mixed $response) => Address
     * @return array<array{address_id: int, success: bool, address: Address, error: ?string}>
     */
    protected function processConcurrently(array $addresses, Closure $requestBuilder, Closure $responseParser): array
    {
        if (empty($addresses)) {
            return [];
        }

        $chunkSize = $this->getChunkSize();
        $concurrentRequests = $this->getConcurrentRequests();
        $allResults = [];

        // Process in chunks
        foreach (array_chunk($addresses, $chunkSize) as $chunkIndex => $chunk) {
            // Apply rate limiting if configured
            $this->applyRateLimit(count($chunk));

            // Process chunk with concurrent HTTP requests
            // Further split into concurrent batches if chunk is larger than concurrent limit
            foreach (array_chunk($chunk, $concurrentRequests) as $concurrentBatch) {
                // A pool-level failure (e.g. cURL thread exhaustion under high
                // concurrency surfacing as "Cannot change a rejected promise to
                // fulfilled") must not abort the whole job — degrade to per-address
                // failures for this batch and keep going.
                try {
                    $responses = Http::pool(function (Pool $pool) use ($concurrentBatch, $requestBuilder) {
                        foreach ($concurrentBatch as $index => $address) {
                            $requestBuilder($pool, $address, $index);
                        }
                    });
                } catch (\Throwable $e) {
                    Log::warning($this->getName().' HTTP pool failed for batch', [
                        'batch_size' => count($concurrentBatch),
                        'error' => $e->getMessage(),
                    ]);
                    $responses = [];
                }

                // Process responses maintaining order
                foreach ($concurrentBatch as $index => $address) {
                    $response = $responses[$index] ?? null;

                    try {
                        if ($response instanceof Response && $response->successful()) {
                            $validatedAddress = $responseParser($address, $response);
                            $allResults[] = [
                                'address_id' => $address->id,
                                'success' => true,
                                'address' => $validatedAddress,
                                'error' => null,
                            ];
                        } else {
                            // Failed pool entries are a ConnectionException, not a Response.
                            $errorMsg = match (true) {
                                $response instanceof Response => 'API error: '.$response->status(),
                                $response instanceof \Throwable => 'Request failed: '.$response->getMessage(),
                                default => 'No response',
                            };
                            $allResults[] = [
                                'address_id' => $address->id,
                                'success' => false,
                                'address' => $this->markAddressFailed($address, $errorMsg),
                                'error' => $errorMsg,
                            ];
                        }
                    } catch (\Throwable $e) {
                        Log::error($this->getName().' Concurrent Validation Error', [
                            'address_id' => $address->id,
                            'error' => $e->getMessage(),
                        ]);
                        $allResults[] = [
                            'address_id' => $address->id,
                            'success' => false,
                            'address' => $this->markAddressFailed($address, $e->getMessage()),
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        $this->markConnected();

        return $allResults;
    }

    /**
     * Apply rate limiting if configured.
     */
    protected function applyRateLimit(int $requestCount): void
    {
        $rateLimit = $this->getRateLimitPerMinute();

        if (! $rateLimit) {
            return;
        }

        $key = 'carrier_rate_limit_'.$this->carrier->id;

        // Wait if we've hit the rate limit
        while (RateLimiter::tooManyAttempts($key, $rateLimit)) {
            $seconds = RateLimiter::availableIn($key);
            Log::info($this->getName().' rate limit reached, waiting', ['seconds' => $seconds]);
            sleep(min($seconds, 60));
        }

        // Record the attempts
        for ($i = 0; $i < $requestCount; $i++) {
            RateLimiter::hit($key, 60);
        }
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
     * Mark an address as failed validation.
     */
    protected function markAddressFailed(Address $address, string $errorMessage): Address
    {
        $address->validation_status = 'invalid';
        $address->validated_by_carrier_id = $this->carrier->id;
        $address->validated_at = now();
        $address->save();

        return $address;
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
