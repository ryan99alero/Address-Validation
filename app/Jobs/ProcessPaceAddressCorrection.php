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
 * rendering. The Contact's address is cleansed against the connection's
 * configured validators (in priority order, with fallback), and only the
 * fields that actually changed are pushed back to the Contact. When the
 * address is successfully validated the JobShipment is flagged corrected so it
 * is never re-checked; on a validator error / no result we leave it unflagged
 * so it can be retried.
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

            $carrierSlugs = $connection->validation_carriers ?: ['smarty', 'ups', 'fedex'];
            $corrected = $this->cleanse($validation, $contact, $carrierSlugs);

            // Only act when the address was actually validated. On an API error / no
            // deliverable result, leave U_addressCorrected unset so it is re-checked.
            if (! $corrected['validated']) {
                SystemLog::create([
                    'category' => 'integration',
                    'type' => 'pace_address_correction',
                    'level' => 'warning',
                    'loggable_type' => IntegrationConnection::class,
                    'loggable_id' => $connection->id,
                    'status' => 'skipped',
                    'summary' => "Address not validated for Contact {$contactId} — left unflagged for retry",
                    'completed_at' => now(),
                    'metadata' => [
                        'contact_id' => $contactId,
                        'shipment_id' => $shipmentId,
                        'carriers_tried' => array_values($carrierSlugs),
                    ],
                ]);

                return;
            }

            // Push ONLY the fields whose validated value differs from the current value.
            $contactObject = $connection->objects()->where('object_name', 'Contact')->first();
            $changes = $this->buildContactChanges($contactObject, $corrected, $contact);
            if (! empty($changes)) {
                $client->updateContact(array_merge($contact, $changes));
            }

            // Validated (even with zero edits) → always flag so it is never re-checked.
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
                'summary' => empty($changes)
                    ? "Pace address validated (no changes) for Contact {$contactId}"
                    : "Pace address corrected & pushed for Contact {$contactId}",
                'completed_at' => now(),
                'metadata' => [
                    'contact_id' => $contactId,
                    'shipment_id' => $shipmentId,
                    'corrected' => $corrected['corrected'],
                    'residential' => $corrected['residential'],
                    'source' => $corrected['source'],
                    'changed_fields' => array_keys($changes),
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
     * Cleanse the source address against the configured validators, trying each in
     * priority order until one returns a usable result. Returns a normalized
     * corrected-address array plus a 'validated' flag (false = API error / no
     * deliverable result, so the shipment must NOT be flagged corrected).
     *
     * @param  array<string, mixed>  $source  The Contact (Pace scalar fields)
     * @param  array<int, string>  $carrierSlugs  Validator slugs in priority order
     * @return array<string, mixed>
     */
    protected function cleanse(AddressValidationService $validation, array $source, array $carrierSlugs): array
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

        $carriers = Carrier::query()
            ->where('is_active', true)
            ->whereIn('slug', $carrierSlugs)
            ->get()
            ->sortBy(fn (Carrier $c): int => array_search($c->slug, $carrierSlugs));

        if ($carriers->isEmpty() || empty($input['input_address_1'])) {
            return $this->normalizeFromInput($input) + ['validated' => false];
        }

        foreach ($carriers as $carrier) {
            $address = Address::create($input + [
                'validation_status' => 'pending',
                'source' => 'api',
            ]);

            try {
                $validated = $validation->validateAddress($address, $carrier->slug);

                // A usable result sets output_address_1 (even for an already-clean
                // address). A carrier error / undeliverable address leaves it null.
                if ($validated->output_address_1 !== null) {
                    return $this->normalizeFromOutput($validated) + ['validated' => true];
                }
            } catch (Throwable $e) {
                // Validator failed — fall through to the next one in priority order.
            } finally {
                $address->delete();
            }
        }

        return $this->normalizeFromInput($input) + ['validated' => false];
    }

    /**
     * Build the Contact writeback from push-enabled mappings, including ONLY the
     * fields whose validated value differs from the Contact's current value.
     *
     * @param  array<string, mixed>  $corrected
     * @param  array<string, mixed>  $current  The Contact as read from Pace
     * @return array<string, mixed>
     */
    protected function buildContactChanges(?IntegrationObject $contactObject, array $corrected, array $current): array
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
            $newValue = $mapping->transformToExternal($corrected[$localKey]);

            if ($this->valueChanged($newValue, $current[$field] ?? null)) {
                $changes[$field] = $newValue;
            }
        }

        return $changes;
    }

    protected function valueChanged(mixed $new, mixed $current): bool
    {
        if (is_bool($new) || is_bool($current)) {
            return (bool) $new !== (bool) $current;
        }

        return trim((string) $new) !== trim((string) $current);
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
