<?php

namespace App\Services\Carriers;

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;

interface CarrierInterface
{
    /**
     * Set the carrier configuration.
     */
    public function setCarrier(Carrier $carrier): self;

    /**
     * Validate a single address.
     */
    public function validateAddress(Address $address): AddressCorrection;

    /**
     * Validate multiple addresses in batch.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
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
