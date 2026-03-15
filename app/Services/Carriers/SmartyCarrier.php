<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmartyCarrier implements CarrierInterface
{
    protected Carrier $carrier;

    public function setCarrier(Carrier $carrier): self
    {
        $this->carrier = $carrier;

        return $this;
    }

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
     * Validate multiple addresses in a single Smarty API call using POST.
     * Smarty supports up to 100 addresses per POST request.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
     */
    public function validateBatch(array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        // Smarty limit is 100 addresses per request
        $batchSize = 100;
        $allCorrections = [];

        // Process in chunks of 100
        foreach (array_chunk($addresses, $batchSize) as $chunk) {
            $corrections = $this->validateBatchChunk($chunk);
            $allCorrections = array_merge($allCorrections, $corrections);
        }

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
     * Get the HTTP client configured for this carrier.
     */
    protected function getHttpClient(): PendingRequest
    {
        return Http::timeout($this->carrier->timeout_seconds)
            ->acceptJson();
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
        $dpvFootnotes = $analysis['dpv_footnotes'] ?? '';

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

    /**
     * Create a failed correction record.
     */
    protected function createFailedCorrection(Address $address, string $errorMessage): AddressCorrection
    {
        $correction = new AddressCorrection([
            'address_id' => $address->id,
            'carrier_id' => $this->carrier->id,
            'validation_status' => AddressCorrection::STATUS_INVALID,
            'raw_response' => ['error' => $errorMessage],
            'validated_at' => now(),
        ]);
        $correction->save();

        return $correction;
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
