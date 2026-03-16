<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\AddressCorrection;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmartyCarrier extends AbstractCarrier
{
    public function getName(): string
    {
        return 'Smarty';
    }

    public function getSlug(): string
    {
        return 'smarty';
    }

    /**
     * Validate a single address using Smarty US Street Address API.
     */
    public function validateAddress(Address $address): AddressCorrection
    {
        $results = $this->validateBatch([$address]);

        return $results[0];
    }

    /**
     * Validate multiple addresses using Smarty batch API.
     * Uses carrier settings for native_batch_size, chunk_size, and concurrent_requests.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
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

        $allCorrections = [];

        // First, split into chunks based on overall chunk_size
        foreach (array_chunk($addresses, $chunkSize) as $chunk) {
            // Apply rate limiting if configured
            $this->applyRateLimit(count($chunk));

            // Split each chunk into native batch sizes and process concurrently
            $nativeBatches = array_chunk($chunk, $nativeBatchSize);

            // Process native batches concurrently (up to concurrent_requests at a time)
            foreach (array_chunk($nativeBatches, $concurrentRequests) as $concurrentBatches) {
                $corrections = $this->validateNativeBatchesConcurrently($concurrentBatches);
                $allCorrections = array_merge($allCorrections, $corrections);
            }
        }

        return $allCorrections;
    }

    /**
     * Process multiple native batches concurrently using HTTP pool.
     *
     * @param  array<array<Address>>  $batches
     * @return array<AddressCorrection>
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

        $authId = $this->carrier->getCredential('auth_id');
        $authToken = $this->carrier->getCredential('auth_token');

        if (empty($authId) || empty($authToken)) {
            $errorMsg = 'Smarty API credentials not configured';
            $allCorrections = [];
            foreach ($batches as $batch) {
                foreach ($batch as $address) {
                    $allCorrections[] = $this->createFailedCorrection($address, $errorMsg);
                }
            }

            return $allCorrections;
        }

        $baseUrl = $this->carrier->getBaseUrl();
        $timeout = $this->carrier->timeout_seconds;
        $authQuery = http_build_query([
            'auth-id' => $authId,
            'auth-token' => $authToken,
        ]);

        // Build concurrent requests for each batch
        $responses = Http::pool(function ($pool) use ($batches, $baseUrl, $timeout, $authQuery) {
            foreach ($batches as $batchIndex => $batch) {
                $addressArray = [];
                foreach ($batch as $index => $address) {
                    $addressArray[] = $this->formatAddressForBatch($address, $index);
                }

                $pool->as($batchIndex)
                    ->timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post($baseUrl.'/street-address?'.$authQuery, $addressArray);
            }
        });

        // Process responses
        $allCorrections = [];

        foreach ($batches as $batchIndex => $batch) {
            $response = $responses[$batchIndex] ?? null;

            try {
                if ($response && $response->successful()) {
                    $corrections = $this->parseBatchResponse($batch, $response->json());
                    $allCorrections = array_merge($allCorrections, $corrections);
                } else {
                    $errorMsg = $response ? 'API error: '.$response->status() : 'No response';
                    foreach ($batch as $address) {
                        $allCorrections[] = $this->createFailedCorrection($address, $errorMsg);
                    }
                }
            } catch (Exception $e) {
                Log::error('Smarty Concurrent Batch Error', [
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);
                foreach ($batch as $address) {
                    $allCorrections[] = $this->createFailedCorrection($address, $e->getMessage());
                }
            }
        }

        $this->markConnected();

        return $allCorrections;
    }

    /**
     * Validate a chunk of addresses (up to 100) in a single POST request.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
     */
    protected function validateBatchChunk(array $addresses): array
    {
        try {
            $authId = $this->carrier->getCredential('auth_id');
            $authToken = $this->carrier->getCredential('auth_token');

            if (empty($authId) || empty($authToken)) {
                throw new Exception('Smarty API credentials not configured');
            }

            // Build the request payload - JSON array of address objects
            $addressArray = [];
            foreach ($addresses as $index => $address) {
                $addressArray[] = $this->formatAddressForBatch($address, $index);
            }

            // POST request with JSON body and auth in query params
            $response = Http::timeout($this->carrier->timeout_seconds)
                ->acceptJson()
                ->asJson()
                ->post($this->carrier->getBaseUrl().'/street-address?'.http_build_query([
                    'auth-id' => $authId,
                    'auth-token' => $authToken,
                ]), $addressArray);

            if (! $response->successful()) {
                $this->markError('API request failed: '.$response->status());

                return array_map(
                    fn (Address $address) => $this->createFailedCorrection($address, 'API request failed: '.$response->body()),
                    $addresses
                );
            }

            $this->markConnected();

            return $this->parseBatchResponse($addresses, $response->json());

        } catch (Exception $e) {
            Log::error('Smarty Batch Address Validation Error', [
                'address_count' => count($addresses),
                'error' => $e->getMessage(),
            ]);
            $this->markError($e->getMessage());

            return array_map(
                fn (Address $address) => $this->createFailedCorrection($address, $e->getMessage()),
                $addresses
            );
        }
    }

    /**
     * Format address for Smarty batch POST request.
     *
     * @return array<string, mixed>
     */
    protected function formatAddressForBatch(Address $address, int $index): array
    {
        // Use source_row_number if available for tracking, otherwise use index
        $inputId = $address->source_row_number
            ? "row_{$address->source_row_number}_id_{$address->id}"
            : "idx_{$index}_id_{$address->id}";

        $data = [
            'input_id' => $inputId,
            'street' => $address->address_line_1,
            'candidates' => 1,
            'match' => 'invalid', // Return results even for invalid addresses
        ];

        if ($address->address_line_2) {
            $data['street2'] = $address->address_line_2;
        }

        if ($address->city) {
            $data['city'] = $address->city;
        }

        if ($address->state) {
            $data['state'] = $address->state;
        }

        if ($address->postal_code) {
            $data['zipcode'] = $address->postal_code;
        }

        if ($address->name || $address->company) {
            $data['addressee'] = $address->company ?: $address->name;
        }

        return $data;
    }

    /**
     * Parse Smarty batch response into AddressCorrections.
     *
     * @param  array<Address>  $addresses
     * @param  array<int, array<string, mixed>>  $responseData
     * @return array<AddressCorrection>
     */
    protected function parseBatchResponse(array $addresses, array $responseData): array
    {
        // Build a map of input_index to response for matching
        $responseMap = [];
        foreach ($responseData as $result) {
            $inputIndex = $result['input_index'] ?? null;
            if ($inputIndex !== null) {
                // Group by input_index (there could be multiple candidates)
                if (! isset($responseMap[$inputIndex])) {
                    $responseMap[$inputIndex] = [];
                }
                $responseMap[$inputIndex][] = $result;
            }
        }

        $corrections = [];

        foreach ($addresses as $index => $address) {
            $results = $responseMap[$index] ?? [];
            $corrections[] = $this->parseResponse($address, $results);
        }

        return $corrections;
    }

    /**
     * Test the connection to Smarty API.
     */
    public function testConnection(): bool
    {
        try {
            $authId = $this->carrier->getCredential('auth_id');
            $authToken = $this->carrier->getCredential('auth_token');

            Log::info('Smarty testConnection', [
                'carrier_id' => $this->carrier->id,
                'auth_type' => $this->carrier->auth_type,
                'has_auth_id' => ! empty($authId),
                'has_auth_token' => ! empty($authToken),
                'base_url' => $this->carrier->getBaseUrl(),
            ]);

            if (empty($authId) || empty($authToken)) {
                throw new Exception('Smarty API credentials not configured (Auth ID and Auth Token required)');
            }

            $url = $this->carrier->getBaseUrl().'/street-address';

            // Test with a simple known-good address using freeform input
            $params = [
                'auth-id' => $authId,
                'auth-token' => $authToken,
                'street' => '1600 Amphitheatre Pkwy, Mountain View, CA 94043',
                'candidates' => 1,
            ];

            Log::info('Smarty API request', [
                'url' => $url,
                'params' => array_merge($params, ['auth-id' => '***', 'auth-token' => '***']),
            ]);

            $response = Http::timeout($this->carrier->timeout_seconds)
                ->acceptJson()
                ->get($url, $params);

            Log::info('Smarty testConnection response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $this->markConnected();

                return true;
            }

            $errorMsg = 'Connection test failed: '.$response->status().' - '.$response->body();
            $this->markError($errorMsg);

            return false;

        } catch (Exception $e) {
            Log::error('Smarty testConnection error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->markError($e->getMessage());

            return false;
        }
    }

    /**
     * Fetch access token - Smarty uses API key auth, not OAuth.
     * This is required by AbstractCarrier but returns empty for API key auth.
     */
    protected function fetchAccessToken(): string
    {
        // Smarty doesn't use OAuth tokens - it uses auth-id and auth-token in query params
        return '';
    }

    /**
     * Parse Smarty API response into AddressCorrection.
     *
     * @param  array<int, array<string, mixed>>  $responseData
     */
    protected function parseResponse(Address $address, array $responseData): AddressCorrection
    {
        // Smarty returns an array of candidates
        $candidatesCount = count($responseData);

        if ($candidatesCount === 0) {
            // No matches found - invalid address
            $correction = new AddressCorrection([
                'address_id' => $address->id,
                'carrier_id' => $this->carrier->id,
                'validation_status' => AddressCorrection::STATUS_INVALID,
                'candidates_count' => 0,
                'confidence_score' => 0.0,
                'raw_response' => $responseData,
                'validated_at' => now(),
            ]);
            $correction->save();

            return $correction;
        }

        // Get the best candidate (first one)
        $candidate = $responseData[0];
        $components = $candidate['components'] ?? [];
        $metadata = $candidate['metadata'] ?? [];
        $analysis = $candidate['analysis'] ?? [];

        // Determine validation status
        $validationStatus = $this->determineValidationStatus($analysis, $candidatesCount);

        // Determine residential/commercial
        $rdi = $metadata['rdi'] ?? null;
        $isResidential = $rdi === 'Residential';
        $classification = match ($rdi) {
            'Residential' => AddressCorrection::CLASSIFICATION_RESIDENTIAL,
            'Commercial' => AddressCorrection::CLASSIFICATION_COMMERCIAL,
            default => AddressCorrection::CLASSIFICATION_UNKNOWN,
        };

        // Build corrected address
        $deliveryLine1 = $candidate['delivery_line_1'] ?? null;
        $deliveryLine2 = $candidate['delivery_line_2'] ?? null;

        $correction = new AddressCorrection([
            'address_id' => $address->id,
            'carrier_id' => $this->carrier->id,
            'validation_status' => $validationStatus,
            'corrected_address_line_1' => $deliveryLine1,
            'corrected_address_line_2' => $deliveryLine2,
            'corrected_city' => $components['city_name'] ?? null,
            'corrected_state' => $components['state_abbreviation'] ?? null,
            'corrected_postal_code' => $components['zipcode'] ?? null,
            'corrected_postal_code_ext' => $components['plus4_code'] ?? null,
            'corrected_country_code' => 'US',
            'is_residential' => $isResidential,
            'classification' => $classification,
            'confidence_score' => $this->calculateConfidenceScore($analysis),
            'candidates_count' => $candidatesCount,
            'raw_response' => $responseData,
            'validated_at' => now(),
        ]);

        $correction->save();

        return $correction;
    }

    /**
     * Determine validation status from Smarty analysis.
     *
     * @param  array<string, mixed>  $analysis
     */
    protected function determineValidationStatus(array $analysis, int $candidatesCount): string
    {
        $dpvMatchCode = $analysis['dpv_match_code'] ?? null;

        // DPV Match Codes:
        // Y = Confirmed valid
        // N = Not confirmed valid
        // S = Secondary address (apartment) missing
        // D = Primary address is missing secondary info
        // blank = Not eligible for DPV

        return match ($dpvMatchCode) {
            'Y' => AddressCorrection::STATUS_VALID,
            'S', 'D' => AddressCorrection::STATUS_AMBIGUOUS,
            'N' => AddressCorrection::STATUS_INVALID,
            default => $candidatesCount > 0
                ? AddressCorrection::STATUS_AMBIGUOUS
                : AddressCorrection::STATUS_INVALID,
        };
    }

    /**
     * Calculate confidence score based on analysis.
     *
     * @param  array<string, mixed>  $analysis
     */
    protected function calculateConfidenceScore(array $analysis): float
    {
        $dpvMatchCode = $analysis['dpv_match_code'] ?? null;

        return match ($dpvMatchCode) {
            'Y' => 1.0,
            'S' => 0.8,
            'D' => 0.7,
            'N' => 0.3,
            default => 0.5,
        };
    }
}
