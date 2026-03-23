<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\Carrier;

interface CarrierInterface
{
    /**
     * Set the carrier configuration.
     */
    public function setCarrier(Carrier $carrier): self;

    /**
     * Validate a single address.
     * Updates the Address model directly with validation results.
     */
    public function validateAddress(Address $address): Address;

    /**
     * Validate multiple addresses in batch.
     *
     * @param  array<Address>  $addresses
     * @return array<Address>
     */
    public function validateBatch(array $addresses): array;

    /**
     * Test the connection to the carrier API.
     */
    public function testConnection(): bool;

    /**
     * Get the carrier name.
     */
    public function getName(): string;

    /**
     * Get the carrier slug identifier.
     */
    public function getSlug(): string;
}
