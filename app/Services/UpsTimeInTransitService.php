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

class UpsTimeInTransitService
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
                Log::warning('UPS Time In Transit API Error', [
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

        // Build origin address once
        $originAddress = $this->buildOriginAddress($originPostalCode, $originCountryCode);

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
                $originAddress
            ) {
                foreach ($addressArray as $index => $address) {
                    $payload = $this->buildPayloadForAddress($address, $originAddress);

                    $pool->as($index)
                        ->withToken($accessToken)
                        ->timeout($timeout)
                        ->acceptJson()
                        ->withHeaders([
                            'transId' => uniqid('tit_'),
                            'transactionSrc' => 'AddressCorrection',
                        ])
                        ->post("{$baseUrl}/api/shipments/v1/transittimes", $payload);
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
                            $originAddress['originPostalCode'],
                            $originAddress['originCountryCode']
                        );
                        $processed++;
                    } else {
                        Log::warning('UPS Time In Transit API Error', [
                            'address_id' => $address->id,
                            'status' => $response->status(),
                            'error' => $response->body(),
                        ]);
                        $failed++;
                    }
                } catch (Exception $e) {
                    Log::warning('UPS Time In Transit Exception', [
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
     * @param  array<string, string>  $originAddress
     * @return array<string, mixed>
     */
    protected function buildPayloadForAddress(Address $address, array $originAddress): array
    {
        // Use validated output address if available, otherwise original input
        $destinationPostalCode = $address->output_postal ?? $address->input_postal;
        $destinationCountryCode = $address->output_country ?? $address->input_country ?? 'US';
        $destinationCity = $address->output_city ?? $address->input_city;
        $destinationState = $address->output_state ?? $address->input_state;

        // Use requested ship date or today
        $shipDate = $address->requested_ship_date ?? now();

        // Ensure ship date is not in the past
        if ($shipDate->isPast() && ! $shipDate->isToday()) {
            $shipDate = now();
        }

        // Residential indicator: 01 = residential, 02 = commercial, empty = unknown
        $residentialIndicator = '';
        if ($address->is_residential !== null) {
            $residentialIndicator = $address->is_residential ? '01' : '02';
        }

        return [
            'originCountryCode' => $originAddress['originCountryCode'],
            'originStateProvince' => $originAddress['originStateProvince'] ?? '',
            'originCityName' => $originAddress['originCityName'] ?? '',
            'originTownName' => '',
            'originPostalCode' => $originAddress['originPostalCode'],
            'destinationCountryCode' => $destinationCountryCode,
            'destinationStateProvince' => $destinationState ?? '',
            'destinationCityName' => $destinationCity ?? '',
            'destinationTownName' => '',
            'destinationPostalCode' => $destinationPostalCode,
            'weight' => '1.0',
            'weightUnitOfMeasure' => 'LBS',
            'shipmentContentsValue' => '100.00',
            'shipmentContentsCurrencyCode' => 'USD',
            'billType' => '03', // 03 = Non-Document
            'shipDate' => $shipDate->format('Y-m-d'),
            'shipTime' => '',
            'residentialIndicator' => $residentialIndicator,
            'avvFlag' => true,
            'numberOfPackages' => '1',
        ];
    }

    /**
     * Fetch transit times from UPS API for a single address.
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

        // Build origin address
        $originAddress = $this->buildOriginAddress($originPostalCode, $originCountryCode);

        // Build payload
        $payload = $this->buildPayloadForAddress($address, $originAddress);

        $response = Http::withToken($accessToken)
            ->timeout($this->carrier->timeout_seconds)
            ->acceptJson()
            ->withHeaders([
                'transId' => uniqid('tit_'),
                'transactionSrc' => 'AddressCorrection',
            ])
            ->post("{$baseUrl}/api/shipments/v1/transittimes", $payload);

        if (! $response->successful()) {
            throw new Exception('UPS Time In Transit API failed: '.$response->body());
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
     * Parse UPS transit times response.
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
        $emsResponse = $responseData['emsResponse'] ?? [];
        $services = $emsResponse['services'] ?? [];

        foreach ($services as $service) {
            $transitTime = $this->createTransitTimeFromService(
                $address,
                $service,
                $originPostalCode,
                $originCountryCode
            );

            if ($transitTime) {
                $transitTimes->push($transitTime);
            }
        }

        return $transitTimes;
    }

    /**
     * Create a TransitTime model from UPS service data.
     *
     * @param  array<string, mixed>  $service
     */
    protected function createTransitTimeFromService(
        Address $address,
        array $service,
        string $originPostalCode,
        string $originCountryCode
    ): ?TransitTime {
        $serviceLevel = $service['serviceLevel'] ?? null;
        $serviceLevelDescription = $service['serviceLevelDescription'] ?? null;

        if (! $serviceLevel) {
            return null;
        }

        // Map UPS service level to a service type code for consistency
        $serviceType = $this->mapServiceLevelToType($serviceLevel, $serviceLevelDescription);

        // Parse delivery date
        $deliveryDate = null;
        if (! empty($service['deliveryDate'])) {
            try {
                $deliveryDate = Carbon::parse($service['deliveryDate']);
            } catch (Exception $e) {
                // Invalid date format
            }
        }

        // Calculate distance if available (UPS sometimes includes this)
        $distanceValue = null;
        $distanceUnits = null;

        $transitTime = TransitTime::updateOrCreate(
            [
                'address_id' => $address->id,
                'carrier_id' => $this->carrier->id,
                'service_type' => $serviceType,
            ],
            [
                'origin_postal_code' => $originPostalCode,
                'origin_country_code' => $originCountryCode,
                'service_name' => $serviceLevelDescription,
                'carrier_code' => $serviceLevel,
                'transit_days_description' => $this->formatTransitDays($service),
                'minimum_transit_time' => $this->mapDaysToEnum($service['businessTransitDays'] ?? null),
                'maximum_transit_time' => $this->mapDaysToEnum($service['totalTransitDays'] ?? null),
                'delivery_date' => $deliveryDate,
                'delivery_time' => $service['deliveryTime'] ?? $service['commitTime'] ?? null,
                'delivery_day_of_week' => $service['deliveryDayOfWeek'] ?? null,
                'cutoff_time' => $service['cstccutoffTime'] ?? $service['pickupTime'] ?? null,
                'distance_value' => $distanceValue,
                'distance_units' => $distanceUnits,
                'raw_response' => $service,
                'calculated_at' => now(),
            ]
        );

        return $transitTime;
    }

    /**
     * Map UPS service level to a standardized service type.
     * UPS service levels: 01, 02, 03, 12, 13, 14, 59, 65, etc.
     */
    protected function mapServiceLevelToType(string $serviceLevel, ?string $description): string
    {
        // UPS service level to type mapping
        $mapping = [
            '01' => 'UPS_NEXT_DAY_AIR',
            '02' => 'UPS_2ND_DAY_AIR',
            '03' => 'UPS_GROUND',
            '05' => 'UPS_EXPEDITED',
            '07' => 'UPS_EXPRESS',
            '08' => 'UPS_EXPEDITED',
            '11' => 'UPS_STANDARD',
            '12' => 'UPS_3_DAY_SELECT',
            '13' => 'UPS_NEXT_DAY_AIR_SAVER',
            '14' => 'UPS_NEXT_DAY_AIR_EARLY',
            '21' => 'UPS_EXPRESS_PLUS',
            '28' => 'UPS_EXPRESS_SAVER',
            '29' => 'UPS_EXPRESS_FREIGHT',
            '54' => 'UPS_EXPRESS_PLUS',
            '59' => 'UPS_2ND_DAY_AIR_AM',
            '65' => 'UPS_SAVER',
            '82' => 'UPS_TODAY_STANDARD',
            '83' => 'UPS_TODAY_DEDICATED_COURIER',
            '84' => 'UPS_TODAY_INTERCITY',
            '85' => 'UPS_TODAY_EXPRESS',
            '86' => 'UPS_TODAY_EXPRESS_SAVER',
            '96' => 'UPS_WORLDWIDE_EXPRESS_FREIGHT',
        ];

        return $mapping[$serviceLevel] ?? 'UPS_SERVICE_'.$serviceLevel;
    }

    /**
     * Format transit days description.
     *
     * @param  array<string, mixed>  $service
     */
    protected function formatTransitDays(array $service): string
    {
        $businessDays = $service['businessTransitDays'] ?? null;
        $totalDays = $service['totalTransitDays'] ?? null;

        if ($businessDays !== null && $totalDays !== null && $businessDays !== $totalDays) {
            return "{$businessDays} Business Days ({$totalDays} Total)";
        } elseif ($businessDays !== null) {
            return "{$businessDays} Business Day".($businessDays > 1 ? 's' : '');
        } elseif ($totalDays !== null) {
            return "{$totalDays} Day".($totalDays > 1 ? 's' : '');
        }

        return 'N/A';
    }

    /**
     * Map days to FedEx-style enum values for consistency.
     */
    protected function mapDaysToEnum(?int $days): ?string
    {
        if ($days === null) {
            return null;
        }

        $mapping = [
            1 => 'ONE_DAY',
            2 => 'TWO_DAYS',
            3 => 'THREE_DAYS',
            4 => 'FOUR_DAYS',
            5 => 'FIVE_DAYS',
            6 => 'SIX_DAYS',
            7 => 'SEVEN_DAYS',
            8 => 'EIGHT_DAYS',
            9 => 'NINE_DAYS',
            10 => 'TEN_DAYS',
        ];

        return $mapping[$days] ?? "{$days}_DAYS";
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
            throw new Exception('UPS API credentials not configured');
        }

        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->timeout($this->carrier->timeout_seconds)
            ->post($this->carrier->getBaseUrl().'/security/v1/oauth/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            throw new Exception('Failed to obtain UPS access token: '.$response->body());
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new Exception('UPS OAuth response missing access_token');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600) - 60;

        return $this->accessToken;
    }

    /**
     * Build origin address for API request.
     * Uses full company address if it matches the provided postal code.
     *
     * @return array<string, string>
     */
    protected function buildOriginAddress(string $postalCode, string $countryCode): array
    {
        $company = CompanySetting::instance();

        // If company postal code matches, use full address for better accuracy
        if ($company->hasAddress() && $company->postal_code === $postalCode) {
            return [
                'originPostalCode' => $company->postal_code,
                'originCountryCode' => $company->country_code ?? 'US',
                'originStateProvince' => $company->state ?? '',
                'originCityName' => $company->city ?? '',
            ];
        }

        // Otherwise just use postal code and country
        return [
            'originPostalCode' => $postalCode,
            'originCountryCode' => $countryCode,
            'originStateProvince' => '',
            'originCityName' => '',
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
