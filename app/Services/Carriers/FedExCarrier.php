<?php

namespace App\Services\Carriers;

use App\Models\Address;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FedExCarrier extends AbstractCarrier
{
    public function getName(): string
    {
        return 'FedEx';
    }

    public function getSlug(): string
    {
        return 'fedex';
    }

    /**
     * Validate a single address using FedEx Address Resolution API.
     */
    public function validateAddress(Address $address): Address
    {
        $results = $this->validateBatch([$address]);

        return $results[0];
    }

    /**
     * Validate multiple addresses using FedEx batch API.
     * Uses carrier settings for native_batch_size, chunk_size, and concurrent_requests.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    public function validateBatch(array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        // Use native batch size from carrier settings
        $nativeBatchSize = $this->getNativeBatchSize();
        $chunkSize = $this->getChunkSize();
        $concurrentRequests = $this->getConcurrentRequests();

        $allResults = [];

        // First, split into chunks based on overall chunk_size
        foreach (array_chunk($addresses, $chunkSize) as $chunk) {
            // Apply rate limiting if configured
            $this->applyRateLimit(count($chunk));

            // Split each chunk into native batch sizes and process concurrently
            $nativeBatches = array_chunk($chunk, $nativeBatchSize);

            // Process native batches concurrently (up to concurrent_requests at a time)
            foreach (array_chunk($nativeBatches, $concurrentRequests) as $concurrentBatches) {
                $results = $this->validateNativeBatchesConcurrently($concurrentBatches);
                $allResults = array_merge($allResults, $results);
            }
        }

        return $allResults;
    }

    /**
     * Process multiple native batches concurrently using HTTP pool.
     *
     * @param  array<array<Address>>  $batches
     * @return array<Address>
     */
    protected function validateNativeBatchesConcurrently(array $batches): array
    {
        if (empty($batches)) {
            return [];
        }

        // If only one batch, process directly (no need for pool)
        if (count($batches) === 1) {
            return $this->validateBatchChunk($batches[0]);
        }

        $accessToken = $this->getAccessToken();
        $baseUrl = $this->carrier->getBaseUrl();
        $timeout = $this->carrier->timeout_seconds;

        // Build concurrent requests for each batch
        $responses = Http::pool(function ($pool) use ($batches, $accessToken, $baseUrl, $timeout) {
            foreach ($batches as $batchIndex => $batch) {
                $addressesToValidate = [];
                foreach ($batch as $index => $address) {
                    $referenceId = $address->source_row_number
                        ? "row_{$address->source_row_number}_id_{$address->id}"
                        : (string) $address->id;

                    $addressesToValidate[] = [
                        'address' => $this->formatAddressForRequest($address),
                        'clientReferenceId' => $referenceId,
                    ];
                }

                $pool->as($batchIndex)
                    ->withToken($accessToken)
                    ->timeout($timeout)
                    ->acceptJson()
                    ->post($baseUrl.'/address/v1/addresses/resolve', [
                        'addressesToValidate' => $addressesToValidate,
                    ]);
            }
        });

        // Process responses
        $allResults = [];

        foreach ($batches as $batchIndex => $batch) {
            $response = $responses[$batchIndex] ?? null;

            try {
                if ($response && $response->successful()) {
                    $results = $this->parseBatchResponse($batch, $response->json());
                    $allResults = array_merge($allResults, $results);
                } else {
                    $errorMsg = $response ? 'API error: '.$response->status() : 'No response';
                    foreach ($batch as $address) {
                        $allResults[] = $this->markAddressFailed($address, $errorMsg);
                    }
                }
            } catch (Exception $e) {
                Log::error('FedEx Concurrent Batch Error', [
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);
                foreach ($batch as $address) {
                    $allResults[] = $this->markAddressFailed($address, $e->getMessage());
                }
            }
        }

        $this->markConnected();

        return $allResults;
    }

    /**
     * Validate a chunk of addresses (up to 100) in a single API call.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    protected function validateBatchChunk(array $addresses): array
    {
        try {
            // Build the request payload with all addresses
            $addressesToValidate = [];
            foreach ($addresses as $index => $address) {
                // Use source_row_number if available (for import tracking), otherwise use address ID
                $referenceId = $address->source_row_number
                    ? "row_{$address->source_row_number}_id_{$address->id}"
                    : (string) $address->id;

                $addressesToValidate[] = [
                    'address' => $this->formatAddressForRequest($address),
                    'clientReferenceId' => $referenceId,
                ];
            }

            $response = $this->getHttpClient()
                ->post($this->carrier->getBaseUrl().'/address/v1/addresses/resolve', [
                    'addressesToValidate' => $addressesToValidate,
                ]);

            if (! $response->successful()) {
                $this->markError('API request failed: '.$response->status());

                // Return failed addresses for all addresses in the batch
                return array_map(
                    fn (Address $address) => $this->markAddressFailed($address, 'API request failed: '.$response->body()),
                    $addresses
                );
            }

            $this->markConnected();

            return $this->parseBatchResponse($addresses, $response->json());

        } catch (Exception $e) {
            Log::error('FedEx Batch Address Validation Error', [
                'address_count' => count($addresses),
                'error' => $e->getMessage(),
            ]);
            $this->markError($e->getMessage());

            // Return failed addresses for all addresses
            return array_map(
                fn (Address $address) => $this->markAddressFailed($address, $e->getMessage()),
                $addresses
            );
        }
    }

    /**
     * Parse FedEx batch response and update Addresses.
     *
     * @param  array<Address>  $addresses
     * @param  array<string, mixed>  $responseData
     * @return array<Address>
     */
    protected function parseBatchResponse(array $addresses, array $responseData): array
    {
        $output = $responseData['output'] ?? [];
        $resolvedAddresses = $output['resolvedAddresses'] ?? [];

        $results = [];

        // FedEx returns resolved addresses in the same order as submitted
        foreach ($addresses as $index => $address) {
            $resolvedAddress = $resolvedAddresses[$index] ?? [];
            $results[] = $this->parseResolvedAddress($address, $resolvedAddress);
        }

        return $results;
    }

    /**
     * Parse a single resolved address from FedEx response.
     *
     * @param  array<string, mixed>  $resolvedAddress
     */
    protected function parseResolvedAddress(Address $address, array $resolvedAddress): Address
    {
        // Determine validation status
        $validationStatus = $this->determineValidationStatus($resolvedAddress);

        // Extract corrected address
        $correctedAddress = $this->extractCorrectedAddress($resolvedAddress);

        // Check classification
        $classification = $this->determineClassification($resolvedAddress);
        $isResidential = $classification === 'residential';
        $confidenceScore = $this->calculateConfidenceScore($resolvedAddress);

        // Update Address directly (denormalized schema)
        $address->update([
            'output_address_1' => $correctedAddress['address_line_1'] ?? null,
            'output_address_2' => $correctedAddress['address_line_2'] ?? null,
            'output_city' => $correctedAddress['city'] ?? null,
            'output_state' => $correctedAddress['state'] ?? null,
            'output_postal' => $correctedAddress['postal_code'] ?? null,
            'output_postal_ext' => $correctedAddress['postal_code_ext'] ?? null,
            'output_country' => $correctedAddress['country_code'] ?? $address->input_country,
            'validation_status' => $validationStatus,
            'is_residential' => $isResidential,
            'classification' => $classification,
            'confidence_score' => $confidenceScore,
            'validated_by_carrier_id' => $this->carrier->id,
            'validated_at' => now(),
        ]);

        return $address;
    }

    /**
     * Test the connection to FedEx API.
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
     * Fetch OAuth access token from FedEx using form-encoded credentials.
     */
    protected function fetchAccessToken(): string
    {
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

        return $data['access_token'];
    }

    /**
     * Format address for FedEx API request.
     *
     * @return array<string, mixed>
     */
    protected function formatAddressForRequest(Address $address): array
    {
        $streetLines = array_filter([
            $address->input_address_1,
            $address->input_address_2,
        ]);

        return [
            'streetLines' => array_values($streetLines),
            'city' => $address->input_city,
            'stateOrProvinceCode' => $address->input_state,
            'postalCode' => $address->input_postal,
            'countryCode' => $address->input_country ?? 'US',
        ];
    }

    /**
     * Determine the validation status from FedEx response.
     *
     * @param  array<string, mixed>  $resolvedAddress
     */
    protected function determineValidationStatus(array $resolvedAddress): string
    {
        if (empty($resolvedAddress)) {
            return 'invalid';
        }

        $attributes = $resolvedAddress['attributes'] ?? [];

        // Check DPV (Delivery Point Validation) - most reliable indicator
        $dpv = ($attributes['DPV'] ?? '') === 'true';
        $matched = ($attributes['Matched'] ?? '') === 'true';
        $resolved = ($attributes['Resolved'] ?? '') === 'true';
        $multipleMatches = ($attributes['MultipleMatches'] ?? '') === 'true';

        // If DPV confirmed and matched, it's valid
        if ($dpv && $matched) {
            return 'valid';
        }

        // If multiple matches, it's ambiguous
        if ($multipleMatches) {
            return 'ambiguous';
        }

        // If resolved but not DPV confirmed, still consider valid (standardized)
        if ($resolved && $matched) {
            return 'valid';
        }

        return 'invalid';
    }

    /**
     * Extract corrected address from FedEx response.
     *
     * @param  array<string, mixed>  $resolvedAddress
     * @return array<string, string|null>
     */
    protected function extractCorrectedAddress(array $resolvedAddress): array
    {
        $streetLines = $resolvedAddress['streetLinesToken'] ?? [];

        // Handle postal code with possible +4 extension
        $postalCode = $resolvedAddress['postalCode'] ?? null;
        $postalCodeExt = null;

        if ($postalCode && str_contains($postalCode, '-')) {
            [$postalCode, $postalCodeExt] = explode('-', $postalCode, 2);
        }

        return [
            'address_line_1' => $streetLines[0] ?? null,
            'address_line_2' => $streetLines[1] ?? null,
            'city' => $resolvedAddress['city'] ?? null,
            'state' => $resolvedAddress['stateOrProvinceCode'] ?? null,
            'postal_code' => $postalCode,
            'postal_code_ext' => $postalCodeExt,
            'country_code' => $resolvedAddress['countryCode'] ?? null,
        ];
    }

    /**
     * Determine address classification from FedEx response.
     *
     * @param  array<string, mixed>  $resolvedAddress
     */
    protected function determineClassification(array $resolvedAddress): string
    {
        $classification = $resolvedAddress['classification'] ?? null;

        return match ($classification) {
            'RESIDENTIAL' => 'residential',
            'BUSINESS' => 'commercial',
            'MIXED' => 'mixed',
            default => 'unknown',
        };
    }

    /**
     * Calculate confidence score from FedEx response.
     *
     * @param  array<string, mixed>  $resolvedAddress
     */
    protected function calculateConfidenceScore(array $resolvedAddress): float
    {
        if (empty($resolvedAddress)) {
            return 0.0;
        }

        $attributes = $resolvedAddress['attributes'] ?? [];

        $dpv = ($attributes['DPV'] ?? '') === 'true';
        $matched = ($attributes['Matched'] ?? '') === 'true';
        $zip4Match = ($attributes['ZIP4Match'] ?? '') === 'true';
        $zip11Match = ($attributes['ZIP11Match'] ?? '') === 'true';
        $multipleMatches = ($attributes['MultipleMatches'] ?? '') === 'true';

        // DPV + ZIP11 match = highest confidence
        if ($dpv && $zip11Match) {
            return 1.0;
        }

        // DPV + ZIP4 match = very high confidence
        if ($dpv && $zip4Match) {
            return 0.95;
        }

        // DPV confirmed = high confidence
        if ($dpv) {
            return 0.9;
        }

        // Matched but not DPV = moderate confidence
        if ($matched) {
            return 0.7;
        }

        // Multiple matches = lower confidence
        if ($multipleMatches) {
            return 0.5;
        }

        return 0.0;
    }
}
