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
use RuntimeException;
use Throwable;

/**
 * Real-time Pace address correction. The inbound punch-out only needs the
 * JobShipment id — we read the shipment + contact straight from the Pace API
 * (authoritative, scalar values) rather than trusting Velocity's field
 * rendering. The Contact's address is cleansed (read-only against the carrier
 * cache, no retention) and the corrected address is pushed back to the Contact,
 * then the JobShipment is flagged.
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

        $shipmentId = $this->payload['shipment_id'] ?? $this->payload['id'] ?? null;
        $contactId = null;

        try {
            $client = new PaceApiClient($connection);

            // Read the shipment for the REAL numeric contactNumber (Velocity renders
            // contactNumber as the contact's display name; the API returns the id).
            $shipment = $shipmentId ? $client->readJobShipment((string) $shipmentId) : [];
            $contactId = $shipment['contactNumber'] ?? $this->payload['contact_id'] ?? $this->payload['contactNumber'] ?? null;

            if (empty($contactId)) {
                throw new RuntimeException("No contactNumber resolved for shipment {$shipmentId}");
            }

            // Read the contact: its current address is what we cleanse, and we need
            // the full object for Pace's read-modify-write update.
            $contact = $client->readContact((string) $contactId);

            $corrected = $this->cleanse($validation, $contact);

            $contactObject = $connection->objects()->where('object_name', 'Contact')->first();
            $changes = $this->buildContactChanges($contactObject, $corrected);
            if (! empty($changes)) {
                $client->updateContact(array_merge($contact, $changes));
            }

            // Flag the shipment so it isn't reprocessed.
            if (! empty($shipment)) {
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
                'summary' => "Pace address correction failed (shipment {$shipmentId}, contact {$contactId})",
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'metadata' => ['contact_id' => $contactId, 'shipment_id' => $shipmentId],
            ]);

            throw $e;
        }
    }

    /**
     * Cleanse the source address against the carrier cache (and a carrier API on a
     * miss) without retaining anything: a transient Address is validated then
     * deleted. Returns a normalized corrected-address array.
     *
     * @param  array<string, mixed>  $source  The Contact (Pace scalar fields)
     * @return array<string, mixed>
     */
    protected function cleanse(AddressValidationService $validation, array $source): array
    {
        $input = [
            'input_address_1' => $source['address1'] ?? null,
            'input_address_2' => $source['address2'] ?? null,
            'input_city' => $source['city'] ?? null,
            'input_state' => $source['state'] ?? null,
            'input_postal' => $source['zip'] ?? null,
            // Pace country is a FK (US=1); domestic correction defaults to US.
            'input_country' => 'US',
            // Company/name are NOT corrected, but Smarty uses them as the
            // "addressee" hint to better resolve the address (firm matching).
            'input_company' => $source['companyName'] ?? $source['name'] ?? null,
            'input_name' => null,
        ];

        $carrier = Carrier::query()
            ->where('is_active', true)
            ->whereIn('slug', ['smarty', 'ups', 'fedex'])
            ->get()
            ->sortBy(fn (Carrier $c): int => array_search($c->slug, ['smarty', 'ups', 'fedex']))
            ->first();

        if (! $carrier || empty($input['input_address_1'])) {
            return $this->normalizeFromInput($input);
        }

        $address = Address::create($input + [
            'validation_status' => 'pending',
            'source' => 'api',
        ]);

        try {
            $validated = $validation->validateAddress($address, $carrier->slug);

            return $this->normalizeFromOutput($validated);
        } catch (Throwable $e) {
            return $this->normalizeFromInput($input);
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
            'address3' => null,
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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeFromInput(array $input): array
    {
        return [
            'address1' => $input['input_address_1'] ?? null,
            'address2' => $input['input_address_2'] ?? null,
            'address3' => null,
            'city' => $input['input_city'] ?? null,
            'state' => $input['input_state'] ?? null,
            'zip' => $input['input_postal'] ?? null,
            'postal_ext' => null,
            'country' => $input['input_country'] ?? 'US',
            'residential' => null,
            'corrected' => false,
            'source' => null,
        ];
    }
}
