<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\Carrier;
use App\Models\IntegrationConnection;
use App\Models\IntegrationObject;
use App\Models\SystemLog;
use App\Services\AddressValidationService;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Real-time Pace address correction: cleanse an inbound JobShipment address
 * (read-only against the carrier cache, no retention) and push the corrected
 * address back to the Contact, then flag the JobShipment.
 */
class ProcessPaceAddressCorrection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $connectionId,
        public array $payload,
    ) {}

    public function handle(AddressValidationService $validation): void
    {
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        $contactId = $this->payload['contact_id'] ?? $this->payload['contactNumber'] ?? null;
        $shipmentId = $this->payload['shipment_id'] ?? $this->payload['id'] ?? null;

        try {
            $corrected = $this->cleanse($validation);

            $client = new PaceApiClient($connection);
            $contactObject = $connection->objects()->where('object_name', 'Contact')->first();

            // Read-modify-write the Contact (Pace updateContact expects the full object).
            $contact = $client->readContact((string) $contactId);
            $changes = $this->buildContactChanges($contactObject, $corrected);
            if (! empty($changes)) {
                $client->updateContact(array_merge($contact, $changes));
            }

            // Flag the shipment so it isn't reprocessed.
            if ($shipmentId) {
                $shipment = $client->readJobShipment((string) $shipmentId);
                $shipment['U_addressCorrected'] = true;
                $client->updateJobShipment($shipment);
            }

            SystemLog::create([
                'category' => 'integration',
                'type' => 'pace_address_correction',
                'level' => 'info',
                'loggable_type' => IntegrationConnection::class,
                'loggable_id' => $connection->id,
                'status' => 'success',
                'summary' => "Pace address corrected & pushed for Contact {$contactId}",
                'completed_at' => now(),
                'metadata' => [
                    'contact_id' => $contactId,
                    'shipment_id' => $shipmentId,
                    'corrected' => $corrected['corrected'],
                    'residential' => $corrected['residential'],
                    'source' => $corrected['source'],
                    'fields_pushed' => array_keys($changes),
                ],
            ]);
        } catch (Throwable $e) {
            $connection->markError($e->getMessage());

            SystemLog::create([
                'category' => 'integration',
                'type' => 'pace_address_correction',
                'level' => 'error',
                'loggable_type' => IntegrationConnection::class,
                'loggable_id' => $connection->id,
                'status' => 'failed',
                'summary' => "Pace address correction failed for Contact {$contactId}",
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'metadata' => ['contact_id' => $contactId, 'shipment_id' => $shipmentId],
            ]);

            throw $e;
        }
    }

    /**
     * Cleanse the inbound address against the carrier cache (and a carrier API on
     * a miss) without retaining anything: a transient Address is validated then
     * deleted. Returns a normalized corrected-address array.
     *
     * @return array<string, mixed>
     */
    protected function cleanse(AddressValidationService $validation): array
    {
        $p = $this->payload;
        $input = [
            'input_address_1' => $p['address1'] ?? null,
            'input_address_2' => $p['address2'] ?? null,
            'input_city' => $p['city'] ?? null,
            'input_state' => $p['state'] ?? null,
            'input_postal' => $p['zip'] ?? $p['postal'] ?? null,
            'input_country' => $p['country'] ?? 'US',
            // Company/name are NOT corrected, but Smarty uses them as the
            // "addressee" hint to better resolve the address (firm matching).
            'input_company' => $p['company'] ?? $p['companyName'] ?? null,
            'input_name' => $p['name'] ?? null,
        ];

        $carrier = Carrier::query()
            ->where('is_active', true)
            ->whereIn('slug', ['smarty', 'ups', 'fedex'])
            ->get()
            ->sortBy(fn (Carrier $c): int => array_search($c->slug, ['smarty', 'ups', 'fedex']))
            ->first();

        if (! $carrier || empty($input['input_address_1'])) {
            return $this->normalizeFromInput();
        }

        $address = Address::create($input + [
            'validation_status' => 'pending',
            'source' => 'api',
        ]);

        try {
            $validated = $validation->validateAddress($address, $carrier->slug);

            return $this->normalizeFromOutput($validated);
        } catch (Throwable $e) {
            return $this->normalizeFromInput();
        } finally {
            $address->delete();
        }
    }

    /**
     * Build the Contact writeback payload from the Contact object's push-enabled
     * field mappings (config-driven, GUI-defined). corrected[local_field] →
     * external_field, with the mapping's transform applied.
     *
     * @param  array<string, mixed>  $corrected
     * @return array<string, mixed>
     */
    protected function buildContactChanges(?IntegrationObject $contactObject, array $corrected): array
    {
        $changes = [];
        if (! $contactObject) {
            return $changes;
        }

        foreach ($contactObject->fieldMappings()->where('sync_on_push', true)->get() as $mapping) {
            $localKey = $mapping->local_field;
            if (empty($localKey) || ! array_key_exists($localKey, $corrected)) {
                continue;
            }

            $field = $mapping->external_field ?: ltrim((string) $mapping->external_xpath, '@');
            $changes[$field] = $mapping->transformToExternal($corrected[$localKey]);
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeFromOutput(Address $a): array
    {
        return [
            'address1' => $a->output_address_1 ?? $a->input_address_1,
            'address2' => $a->output_address_2 ?? $a->input_address_2,
            'address3' => $this->payload['address3'] ?? null,
            'city' => $a->output_city ?? $a->input_city,
            'state' => $a->output_state ?? $a->input_state,
            'zip' => $a->output_postal ?? $a->input_postal,
            'postal_ext' => $a->output_postal_ext,
            'country' => $a->output_country ?? $a->input_country,
            'residential' => $a->is_residential,
            'corrected' => $a->output_address_1 !== null && $a->output_address_1 !== $a->input_address_1,
            'source' => $a->validation_source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeFromInput(): array
    {
        $p = $this->payload;

        return [
            'address1' => $p['address1'] ?? null,
            'address2' => $p['address2'] ?? null,
            'address3' => $p['address3'] ?? null,
            'city' => $p['city'] ?? null,
            'state' => $p['state'] ?? null,
            'zip' => $p['zip'] ?? $p['postal'] ?? null,
            'postal_ext' => null,
            'country' => $p['country'] ?? 'US',
            'residential' => null,
            'corrected' => false,
            'source' => null,
        ];
    }
}
