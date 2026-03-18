<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Carrier;
use App\Models\CompanySetting;
use App\Models\TransitTime;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FedExServiceAvailabilityService
{
    protected ?string $accessToken = null;

    protected ?int $tokenExpiresAt = null;

    public function __construct(
        protected Carrier $carrier
    ) {}

    /**
     * Get transit times for a single address.
     *
     * @return Collection<int, TransitTime>
     */
    public function getTransitTimes(
        Address $address,
        ?string $originPostalCode = null,
        ?string $originCountryCode = null
    ): Collection {
        // Use company settings as default if not provided
        if (! $originPostalCode) {
            $company = CompanySetting::instance();
            $originPostalCode = $company->postal_code;
            $originCountryCode = $originCountryCode ?? $company->country_code ?? 'US';
        }

        if (! $originPostalCode) {
            throw new Exception('Origin postal code required. Configure in Company Setup or provide explicitly.');
        }

        return $this->getTransitTimesForAddresses(
            collect([$address]),
            $originPostalCode,
            $originCountryCode ?? 'US'
        )->get($address->id, collect());
    }

    /**
     * Get transit times for multiple addresses (sequential - for small batches).
     *
     * @param  Collection<int, Address>  $addresses
     * @return Collection<int, Collection<int, TransitTime>>
     */
    public function getTransitTimesForAddresses(
        Collection $addresses,
        string $originPostalCode,
        string $originCountryCode = 'US'
    ): Collection {
        $results = collect();

        foreach ($addresses as $address) {
            try {
                $transitTimes = $this->fetchTransitTimesForAddress(
                    $address,
                    $originPostalCode,
                    $originCountryCode
                );
                $results->put($address->id, $transitTimes);
            } catch (Exception $e) {
                Log::warning('FedEx Transit Times API Error', [
                    'address_id' => $address->id,
                    'error' => $e->getMessage(),
                ]);
                $results->put($address->id, collect());
            }
        }

        return $results;
    }

    /**
     * Get transit times for multiple addresses using concurrent HTTP requests.
     * Much faster for large batches - processes up to $concurrentRequests at a time.
     *
     * @param  Collection<int, Address>  $addresses
     * @param  int  $concurrentRequests  Number of concurrent API calls (default: 10)
     * @return array{processed: int, failed: int}
     */
    public function getTransitTimesBatch(
        Collection $addresses,
        string $originPostalCode,
        string $originCountryCode = 'US',
        int $concurrentRequests = 10
    ): array {
        if ($addresses->isEmpty()) {
            return ['processed' => 0, 'failed' => 0];
        }

        $accessToken = $this->getAccessToken();
        $baseUrl = $this->carrier->getBaseUrl();
        $timeout = $this->carrier->timeout_seconds;

        // Build shipper address once
        $shipperAddress = $this->buildShipperAddress($originPostalCode, $originCountryCode);

        $processed = 0;
        $failed = 0;

        // Process in concurrent batches
        foreach ($addresses->chunk($concurrentRequests) as $chunk) {
            // Build the address array with index for reference
            $addressArray = $chunk->values()->all();

            // Make concurrent requests using HTTP pool
            $responses = Http::pool(function (Pool $pool) use (
                $addressArray,
                $accessToken,
                $baseUrl,
                $timeout,
                $shipperAddress
            ) {
                foreach ($addressArray as $index => $address) {
                    $payload = $this->buildPayloadForAddress($address, $shipperAddress);

                    $pool->as($index)
                        ->withToken($accessToken)
                        ->timeout($timeout)
                        ->acceptJson()
                        ->post("{$baseUrl}/availability/v1/transittimes", $payload);
                }
            });

            // Process responses
            foreach ($addressArray as $index => $address) {
                try {
                    $response = $responses[$index];

                    if ($response->successful()) {
                        $this->parseTransitTimesResponse(
                            $address,
                            $response->json(),
                            $originPostalCode,
                            $originCountryCode
                        );
                        $processed++;
                    } else {
                        Log::warning('FedEx Transit Times API Error', [
                            'address_id' => $address->id,
                            'status' => $response->status(),
                            'error' => $response->body(),
                        ]);
                        $failed++;
                    }
                } catch (Exception $e) {
                    Log::warning('FedEx Transit Times Exception', [
                        'address_id' => $address->id,
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Build API payload for a single address.
     *
     * @param  array<string, string>  $shipperAddress
     * @return array<string, mixed>
     */
    protected function buildPayloadForAddress(Address $address, array $shipperAddress): array
    {
        // Use corrected address if available, otherwise original
        $correction = $address->latestCorrection;
        $destinationPostalCode = $correction?->corrected_postal_code ?? $address->postal_code;
        $destinationCountryCode = $correction?->corrected_country_code ?? $address->country_code ?? 'US';

        return [
            'requestedShipment' => [
                'shipper' => [
                    'address' => $shipperAddress,
                ],
                'recipients' => [
                    [
                        'address' => [
                            'postalCode' => $destinationPostalCode,
                            'countryCode' => $destinationCountryCode,
                        ],
                    ],
                ],
                'packagingType' => 'YOUR_PACKAGING',
                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => '1',
                        ],
                    ],
                ],
            ],
            'carrierCodes' => ['FDXE', 'FDXG'],
        ];
    }

    /**
     * Fetch transit times from FedEx API for a single address.
     *
     * @return Collection<int, TransitTime>
     */
    protected function fetchTransitTimesForAddress(
        Address $address,
        string $originPostalCode,
        string $originCountryCode
    ): Collection {
        $accessToken = $this->getAccessToken();
        $baseUrl = $this->carrier->getBaseUrl();

        // Use corrected address if available, otherwise original
        $correction = $address->latestCorrection;
        $destinationPostalCode = $correction?->corrected_postal_code ?? $address->postal_code;
        $destinationCountryCode = $correction?->corrected_country_code ?? $address->country_code ?? 'US';

        // Build shipper address - use full company address if available
        $shipperAddress = $this->buildShipperAddress($originPostalCode, $originCountryCode);

        $payload = [
            'requestedShipment' => [
                'shipper' => [
                    'address' => $shipperAddress,
                ],
                'recipients' => [
                    [
                        'address' => [
                            'postalCode' => $destinationPostalCode,
                            'countryCode' => $destinationCountryCode,
                        ],
                    ],
                ],
                'packagingType' => 'YOUR_PACKAGING',
                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => '1',
                        ],
                    ],
                ],
            ],
            'carrierCodes' => ['FDXE', 'FDXG'],
        ];

        $response = Http::withToken($accessToken)
            ->timeout($this->carrier->timeout_seconds)
            ->acceptJson()
            ->post("{$baseUrl}/availability/v1/transittimes", $payload);

        if (! $response->successful()) {
            throw new Exception('FedEx Transit Times API failed: '.$response->body());
        }

        $data = $response->json();

        return $this->parseTransitTimesResponse(
            $address,
            $data,
            $originPostalCode,
            $originCountryCode
        );
    }

    /**
     * Parse FedEx transit times response.
     *
     * @param  array<string, mixed>  $responseData
     * @return Collection<int, TransitTime>
     */
    protected function parseTransitTimesResponse(
        Address $address,
        array $responseData,
        string $originPostalCode,
        string $originCountryCode
    ): Collection {
        $transitTimes = collect();
        $output = $responseData['output'] ?? [];
        $transitTimesList = $output['transitTimes'] ?? [];

        foreach ($transitTimesList as $transitTimeGroup) {
            $details = $transitTimeGroup['transitTimeDetails'] ?? [];

            foreach ($details as $detail) {
                $transitTime = $this->createTransitTimeFromDetail(
                    $address,
                    $detail,
                    $originPostalCode,
                    $originCountryCode
                );

                if ($transitTime) {
                    $transitTimes->push($transitTime);
                }
            }
        }

        return $transitTimes;
    }

    /**
     * Create a TransitTime model from API detail.
     *
     * @param  array<string, mixed>  $detail
     */
    protected function createTransitTimeFromDetail(
        Address $address,
        array $detail,
        string $originPostalCode,
        string $originCountryCode
    ): ?TransitTime {
        $serviceType = $detail['serviceType'] ?? null;

        if (! $serviceType) {
            return null;
        }

        $commit = $detail['commit'] ?? [];
        $transitDays = $commit['transitDays'] ?? [];
        $dateDetail = $commit['dateDetail'] ?? [];
        $distance = $detail['distance'] ?? [];

        // Parse delivery date
        $deliveryDate = null;
        $deliveryTime = null;

        if (! empty($dateDetail['day'])) {
            try {
                $deliveryDate = Carbon::parse($dateDetail['day']);
            } catch (Exception $e) {
                // Invalid date format
            }
        }

        if (! empty($dateDetail['time'])) {
            $deliveryTime = $dateDetail['time'];
        }

        $transitTime = TransitTime::updateOrCreate(
            [
                'address_id' => $address->id,
                'carrier_id' => $this->carrier->id,
                'service_type' => $serviceType,
            ],
            [
                'origin_postal_code' => $originPostalCode,
                'origin_country_code' => $originCountryCode,
                'service_name' => $detail['serviceName'] ?? null,
                'carrier_code' => null,
                'transit_days_description' => $transitDays['description'] ?? null,
                'minimum_transit_time' => $transitDays['minimumTransitTime'] ?? null,
                'maximum_transit_time' => $transitDays['maximumTransitTime'] ?? null,
                'delivery_date' => $deliveryDate,
                'delivery_time' => $deliveryTime,
                'delivery_day_of_week' => $dateDetail['dayOfWeek'] ?? null,
                'cutoff_time' => $commit['cutOffTime'] ?? null,
                'distance_value' => $distance['value'] ?? null,
                'distance_units' => $distance['units'] ?? null,
                'raw_response' => $detail,
                'calculated_at' => now(),
            ]
        );

        return $transitTime;
    }

    /**
     * Get OAuth access token.
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $clientId = $this->carrier->getCredential('client_id');
        $clientSecret = $this->carrier->getCredential('client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('FedEx API credentials not configured');
        }

        $response = Http::asForm()
            ->timeout($this->carrier->timeout_seconds)
            ->post($this->carrier->getBaseUrl().'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            throw new Exception('Failed to obtain FedEx access token: '.$response->body());
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new Exception('FedEx OAuth response missing access_token');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600) - 60;

        return $this->accessToken;
    }

    /**
     * Build shipper address for API request.
     * Uses full company address if it matches the provided postal code.
     *
     * @return array<string, string>
     */
    protected function buildShipperAddress(string $postalCode, string $countryCode): array
    {
        $company = CompanySetting::instance();

        // If company postal code matches, use full address for better accuracy
        if ($company->hasAddress() && $company->postal_code === $postalCode) {
            return $company->toFedExAddress();
        }

        // Otherwise just use postal code and country
        return [
            'postalCode' => $postalCode,
            'countryCode' => $countryCode,
        ];
    }

    /**
     * Get the carrier used by this service.
     */
    public function getCarrier(): Carrier
    {
        return $this->carrier;
    }
}
