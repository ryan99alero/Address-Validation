<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\AddressCorrection;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpsCarrier extends AbstractCarrier
{
    public function getName(): string
    {
        return 'UPS';
    }

    public function getSlug(): string
    {
        return 'ups';
    }

    /**
     * Validate a single address using UPS Address Validation API.
     */
    public function validateAddress(Address $address): AddressCorrection
    {
        try {
            $response = $this->getHttpClient()
                ->post($this->carrier->getBaseUrl().'/api/addressvalidation/v1/3', [
                    'XAVRequest' => [
                        'AddressKeyFormat' => $this->formatAddressForRequest($address),
                    ],
                ]);

            if (! $response->successful()) {
                $this->markError('API request failed: '.$response->status());

                return $this->createFailedCorrection($address, 'API request failed: '.$response->body());
            }

            $this->markConnected();

            return $this->parseResponse($address, $response->json());

        } catch (Exception $e) {
            Log::error('UPS Address Validation Error', [
                'address_id' => $address->id,
                'error' => $e->getMessage(),
            ]);
            $this->markError($e->getMessage());

            return $this->createFailedCorrection($address, $e->getMessage());
        }
    }

    /**
     * Test the connection to UPS API.
     */
    public function testConnection(): bool
    {
        try {
            $token = $this->fetchAccessToken();

            if (! empty($token)) {
                $this->markConnected();

                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->markError($e->getMessage());

            return false;
        }
    }

    /**
     * Fetch OAuth access token from UPS using Basic Auth.
     */
    protected function fetchAccessToken(): string
    {
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

        return $data['access_token'];
    }

    /**
     * Format address for UPS API request.
     *
     * @return array<string, mixed>
     */
    protected function formatAddressForRequest(Address $address): array
    {
        $addressLines = array_filter([
            $address->address_line_1,
            $address->address_line_2,
        ]);

        return [
            'AddressLine' => $addressLines,
            'PoliticalDivision2' => $address->city,
            'PoliticalDivision1' => $address->state,
            'PostcodePrimaryLow' => $address->postal_code,
            'CountryCode' => $address->country_code ?? 'US',
        ];
    }

    /**
     * Parse UPS API response into AddressCorrection.
     *
     * @param  array<string, mixed>  $responseData
     */
    protected function parseResponse(Address $address, array $responseData): AddressCorrection
    {
        $xavResponse = $responseData['XAVResponse'] ?? [];

        // Determine validation status
        $validationStatus = $this->determineValidationStatus($xavResponse);

        // Get the candidate address (first one if multiple)
        $candidate = $this->extractBestCandidate($xavResponse);
        $candidatesCount = $this->countCandidates($xavResponse);

        // Check if residential
        $isResidential = $this->isResidentialAddress($xavResponse);
        $classification = $this->determineClassification($xavResponse);

        $correction = new AddressCorrection([
            'address_id' => $address->id,
            'carrier_id' => $this->carrier->id,
            'validation_status' => $validationStatus,
            'corrected_address_line_1' => $candidate['address_line_1'] ?? null,
            'corrected_address_line_2' => $candidate['address_line_2'] ?? null,
            'corrected_city' => $candidate['city'] ?? null,
            'corrected_state' => $candidate['state'] ?? null,
            'corrected_postal_code' => $candidate['postal_code'] ?? null,
            'corrected_postal_code_ext' => $candidate['postal_code_ext'] ?? null,
            'corrected_country_code' => $candidate['country_code'] ?? $address->country_code,
            'is_residential' => $isResidential,
            'classification' => $classification,
            'confidence_score' => $this->calculateConfidenceScore($xavResponse),
            'candidates_count' => $candidatesCount,
            'raw_response' => $responseData,
            'validated_at' => now(),
        ]);

        $correction->save();

        return $correction;
    }

    /**
     * Determine the validation status from UPS response.
     *
     * @param  array<string, mixed>  $xavResponse
     */
    protected function determineValidationStatus(array $xavResponse): string
    {
        // Check for ValidAddressIndicator
        if (isset($xavResponse['ValidAddressIndicator'])) {
            return AddressCorrection::STATUS_VALID;
        }

        // Check for AmbiguousAddressIndicator
        if (isset($xavResponse['AmbiguousAddressIndicator'])) {
            return AddressCorrection::STATUS_AMBIGUOUS;
        }

        // Check for NoCandidatesIndicator
        if (isset($xavResponse['NoCandidatesIndicator'])) {
            return AddressCorrection::STATUS_INVALID;
        }

        // Default to invalid if we can't determine
        return AddressCorrection::STATUS_INVALID;
    }

    /**
     * Extract the best candidate address from response.
     *
     * @param  array<string, mixed>  $xavResponse
     * @return array<string, string|null>
     */
    protected function extractBestCandidate(array $xavResponse): array
    {
        $candidate = $xavResponse['Candidate'][0] ?? $xavResponse['Candidate'] ?? null;

        if (! $candidate) {
            return [];
        }

        $addressKeyFormat = $candidate['AddressKeyFormat'] ?? [];

        // Handle AddressLine which can be string or array
        $addressLines = $addressKeyFormat['AddressLine'] ?? [];
        if (is_string($addressLines)) {
            $addressLines = [$addressLines];
        }

        return [
            'address_line_1' => $addressLines[0] ?? null,
            'address_line_2' => $addressLines[1] ?? null,
            'city' => $addressKeyFormat['PoliticalDivision2'] ?? null,
            'state' => $addressKeyFormat['PoliticalDivision1'] ?? null,
            'postal_code' => $addressKeyFormat['PostcodePrimaryLow'] ?? null,
            'postal_code_ext' => $addressKeyFormat['PostcodeExtendedLow'] ?? null,
            'country_code' => $addressKeyFormat['CountryCode'] ?? null,
        ];
    }

    /**
     * Count the number of candidate addresses.
     *
     * @param  array<string, mixed>  $xavResponse
     */
    protected function countCandidates(array $xavResponse): int
    {
        $candidates = $xavResponse['Candidate'] ?? [];

        if (empty($candidates)) {
            return 0;
        }

        // Check if it's a single candidate (associative array) or multiple
        if (isset($candidates['AddressKeyFormat'])) {
            return 1;
        }

        return count($candidates);
    }

    /**
     * Check if the address is residential.
     *
     * @param  array<string, mixed>  $xavResponse
     */
    protected function isResidentialAddress(array $xavResponse): ?bool
    {
        $candidate = $xavResponse['Candidate'][0] ?? $xavResponse['Candidate'] ?? null;

        if (! $candidate) {
            return null;
        }

        $addressClassification = $candidate['AddressClassification'] ?? [];
        $code = $addressClassification['Code'] ?? null;

        // UPS codes: 0 = Unknown, 1 = Commercial, 2 = Residential
        return match ($code) {
            '2' => true,
            '1' => false,
            default => null,
        };
    }

    /**
     * Determine address classification.
     *
     * @param  array<string, mixed>  $xavResponse
     */
    protected function determineClassification(array $xavResponse): string
    {
        $candidate = $xavResponse['Candidate'][0] ?? $xavResponse['Candidate'] ?? null;

        if (! $candidate) {
            return AddressCorrection::CLASSIFICATION_UNKNOWN;
        }

        $addressClassification = $candidate['AddressClassification'] ?? [];
        $code = $addressClassification['Code'] ?? null;

        return match ($code) {
            '1' => AddressCorrection::CLASSIFICATION_COMMERCIAL,
            '2' => AddressCorrection::CLASSIFICATION_RESIDENTIAL,
            '0' => AddressCorrection::CLASSIFICATION_UNKNOWN,
            default => AddressCorrection::CLASSIFICATION_UNKNOWN,
        };
    }

    /**
     * Calculate a confidence score based on response indicators.
     *
     * @param  array<string, mixed>  $xavResponse
     */
    protected function calculateConfidenceScore(array $xavResponse): float
    {
        // UPS doesn't provide a direct confidence score
        // We calculate based on validation indicators
        if (isset($xavResponse['ValidAddressIndicator'])) {
            return 1.0;
        }

        if (isset($xavResponse['AmbiguousAddressIndicator'])) {
            $candidateCount = $this->countCandidates($xavResponse);

            // More candidates = less confidence
            return max(0.3, 0.8 - ($candidateCount * 0.1));
        }

        return 0.0;
    }

    /**
     * Validate multiple addresses using concurrent HTTP requests.
     * UPS doesn't have a native batch API, so we use HTTP pool for parallel requests.
     * Uses carrier settings for chunk_size and concurrent_requests.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
     */
    public function validateBatch(array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        // Get a single access token for all requests in this batch
        $accessToken = $this->fetchAccessToken();
        $baseUrl = $this->carrier->getBaseUrl();
        $timeout = $this->carrier->timeout_seconds;

        // Use the parent's concurrent processing with carrier settings
        $results = $this->processConcurrently(
            $addresses,
            // Request builder
            function ($pool, $address, $index) use ($accessToken, $baseUrl, $timeout) {
                $pool->as($index)
                    ->withToken($accessToken)
                    ->timeout($timeout)
                    ->post($baseUrl.'/api/addressvalidation/v1/3', [
                        'XAVRequest' => [
                            'AddressKeyFormat' => $this->formatAddressForRequest($address),
                        ],
                    ]);
            },
            // Response parser
            function ($address, $response) {
                return $this->parseResponse($address, $response->json());
            }
        );

        // Extract corrections from results
        $corrections = [];
        foreach ($results as $result) {
            if ($result['correction']) {
                $corrections[] = $result['correction'];
            }
        }

        return $corrections;
    }

    /**
     * Validate addresses concurrently and return detailed results.
     *
     * @param  array<Address>  $addresses
     * @return array<array{address_id: int, success: bool, correction: ?AddressCorrection, error: ?string}>
     */
    public function validateAddressesConcurrently(array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        $accessToken = $this->fetchAccessToken();
        $baseUrl = $this->carrier->getBaseUrl();
        $timeout = $this->carrier->timeout_seconds;

        return $this->processConcurrently(
            $addresses,
            function ($pool, $address, $index) use ($accessToken, $baseUrl, $timeout) {
                $pool->as($index)
                    ->withToken($accessToken)
                    ->timeout($timeout)
                    ->post($baseUrl.'/api/addressvalidation/v1/3', [
                        'XAVRequest' => [
                            'AddressKeyFormat' => $this->formatAddressForRequest($address),
                        ],
                    ]);
            },
            function ($address, $response) {
                return $this->parseResponse($address, $response->json());
            }
        );
    }
}
