<?php

namespace App\Services;

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\FedExCarrier;
use App\Services\Carriers\SmartyCarrier;
use App\Services\Carriers\UpsCarrier;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class AddressValidationService
{
    /**
     * Get a carrier service instance by slug.
     */
    public function getCarrierService(string $slug): CarrierInterface
    {
        $carrier = Carrier::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->createCarrierService($carrier);
    }

    /**
     * Get a carrier service instance by Carrier model.
     */
    public function getCarrierServiceForCarrier(Carrier $carrier): CarrierInterface
    {
        return $this->createCarrierService($carrier);
    }

    /**
     * Create the appropriate carrier service instance.
     */
    protected function createCarrierService(Carrier $carrier): CarrierInterface
    {
        $service = match ($carrier->slug) {
            'ups' => new UpsCarrier,
            'fedex' => new FedExCarrier,
            'smarty' => new SmartyCarrier,
            default => throw new Exception("Unsupported carrier: {$carrier->slug}"),
        };

        return $service->setCarrier($carrier);
    }

    /**
     * Validate a single address using the specified carrier.
     */
    public function validateAddress(Address $address, string $carrierSlug): AddressCorrection
    {
        $service = $this->getCarrierService($carrierSlug);

        return $service->validateAddress($address);
    }

    /**
     * Validate multiple addresses using the specified carrier.
     *
     * @param  array<Address>  $addresses
     * @return array<AddressCorrection>
     */
    public function validateBatch(array $addresses, string $carrierSlug): array
    {
        $service = $this->getCarrierService($carrierSlug);

        return $service->validateBatch($addresses);
    }

    /**
     * Test connection for a specific carrier.
     */
    public function testConnection(string $carrierSlug): bool
    {
        $service = $this->getCarrierService($carrierSlug);

        return $service->testConnection();
    }

    /**
     * Get all active carriers.
     *
     * @return Collection<int, Carrier>
     */
    public function getActiveCarriers()
    {
        return Carrier::active()->get();
    }

    /**
     * Validate multiple addresses concurrently.
     *
     * @param  array<Address>  $addresses
     * @return array<array{address_id: int, success: bool, correction: ?AddressCorrection, error: ?string}>
     */
    public function validateAddressesConcurrently(array $addresses, string $carrierSlug): array
    {
        $service = $this->getCarrierService($carrierSlug);

        // Use concurrent method if available on the carrier
        if (method_exists($service, 'validateAddressesConcurrently')) {
            return $service->validateAddressesConcurrently($addresses);
        }

        // Fallback to sequential processing
        $results = [];
        foreach ($addresses as $address) {
            try {
                $correction = $service->validateAddress($address);
                $results[] = [
                    'address_id' => $address->id,
                    'success' => true,
                    'correction' => $correction,
                    'error' => null,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'address_id' => $address->id,
                    'success' => false,
                    'correction' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
