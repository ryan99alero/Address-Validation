<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\AddressCorrection;
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
    public function validateAddress(Address $address): AddressCorrection
    {
        $results = $this->validateBatch([$address]);

        return $results[0];
    }

    /**
     * Validate multiple addresses in a single FedEx API call.
     * FedEx supports up to 100 addresses per request.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
     */
    public function validateBatch(array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        // FedEx limit is 100 addresses per request
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
     * Validate a chunk of addresses (up to 100) in a single API call.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
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

                // Return failed corrections for all addresses in the batch
                return array_map(
                    fn (Address $address) => $this->createFailedCorrection($address, 'API request failed: '.$response->body()),
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

            // Return failed corrections for all addresses
            return array_map(
                fn (Address $address) => $this->createFailedCorrection($address, $e->getMessage()),
                $addresses
            );
        }
    }

    /**
     * Parse FedEx batch response into AddressCorrections.
     *
     * @param  array<Address>  $addresses
     * @param  array<string, mixed>  $responseData
     * @return array<AddressCorrection>
     */
    protected function parseBatchResponse(array $addresses, array $responseData): array
    {
        $output = $responseData['output'] ?? [];
        $resolvedAddresses = $output['resolvedAddresses'] ?? [];

        $corrections = [];

        // FedEx returns resolved addresses in the same order as submitted
        foreach ($addresses as $index => $address) {
            $resolvedAddress = $resolvedAddresses[$index] ?? [];
            $corrections[] = $this->parseResolvedAddress($address, $resolvedAddress, $responseData);
        }

        return $corrections;
    }

    /**
     * Parse a single resolved address from FedEx response.
     *
     * @param  array<string, mixed>  $resolvedAddress
     * @param  array<string, mixed>  $fullResponseData
     */
    protected function parseResolvedAddress(Address $address, array $resolvedAddress, array $fullResponseData): AddressCorrection
    {
        // Determine validation status
        $validationStatus = $this->determineValidationStatus($resolvedAddress);

        // Extract corrected address
        $correctedAddress = $this->extractCorrectedAddress($resolvedAddress);

        // Check classification
        $classification = $this->determineClassification($resolvedAddress);
        $isResidential = $classification === AddressCorrection::CLASSIFICATION_RESIDENTIAL;

        $correction = new AddressCorrection([
            'address_id' => $address->id,
            'carrier_id' => $this->carrier->id,
            'validation_status' => $validationStatus,
            'corrected_address_line_1' => $correctedAddress['address_line_1'] ?? null,
            'corrected_address_line_2' => $correctedAddress['address_line_2'] ?? null,
            'corrected_city' => $correctedAddress['city'] ?? null,
            'corrected_state' => $correctedAddress['state'] ?? null,
            'corrected_postal_code' => $correctedAddress['postal_code'] ?? null,
            'corrected_postal_code_ext' => $correctedAddress['postal_code_ext'] ?? null,
            'corrected_country_code' => $correctedAddress['country_code'] ?? $address->country_code,
            'is_residential' => $isResidential,
            'classification' => $classification,
            'confidence_score' => $this->calculateConfidenceScore($resolvedAddress),
            'candidates_count' => 1, // In batch mode, we get one result per address
            'raw_response' => $resolvedAddress, // Store individual address response
            'validated_at' => now(),
        ]);

        $correction->save();

        return $correction;
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
            $address->address_line_1,
            $address->address_line_2,
        ]);

        return [
            'streetLines' => array_values($streetLines),
            'city' => $address->city,
            'stateOrProvinceCode' => $address->state,
            'postalCode' => $address->postal_code,
            'countryCode' => $address->country_code ?? 'US',
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
            return AddressCorrection::STATUS_INVALID;
        }

        $attributes = $resolvedAddress['attributes'] ?? [];

        // Check DPV (Delivery Point Validation) - most reliable indicator
        $dpv = ($attributes['DPV'] ?? '') === 'true';
        $matched = ($attributes['Matched'] ?? '') === 'true';
        $resolved = ($attributes['Resolved'] ?? '') === 'true';
        $multipleMatches = ($attributes['MultipleMatches'] ?? '') === 'true';

        // If DPV confirmed and matched, it's valid
        if ($dpv && $matched) {
            return AddressCorrection::STATUS_VALID;
        }

        // If multiple matches, it's ambiguous
        if ($multipleMatches) {
            return AddressCorrection::STATUS_AMBIGUOUS;
        }

        // If resolved but not DPV confirmed, still consider valid (standardized)
        if ($resolved && $matched) {
            return AddressCorrection::STATUS_VALID;
        }

        return AddressCorrection::STATUS_INVALID;
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
            'RESIDENTIAL' => AddressCorrection::CLASSIFICATION_RESIDENTIAL,
            'BUSINESS' => AddressCorrection::CLASSIFICATION_COMMERCIAL,
            'MIXED' => AddressCorrection::CLASSIFICATION_MIXED,
            default => AddressCorrection::CLASSIFICATION_UNKNOWN,
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
