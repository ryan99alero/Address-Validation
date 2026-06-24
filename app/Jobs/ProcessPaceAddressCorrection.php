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
 * Real-time Pace address correction. The inbound punch-out provides the
 * contact id + address directly, so we consume the payload with NO Pace reads in
 * the normal path (a JobShipment read is a fallback only if the contact id is
 * missing). The payload address is cleansed against the connection's configured
 * validators (priority order with fallback); only the fields that actually
 * changed are pushed back to the Contact as a partial merge — {id + changed
 * fields}, verified safe — config-driven by the object's field mappings. We do
 * NOT write the JobShipment (the trackingNumber constraint gates re-firing).
 * Before/after values are recorded for the correction audit.
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
        $jobNumber = $this->payload['job_number'] ?? $this->payload['job'] ?? null;
        $contactId = $this->payload['contact_id'] ?? $this->payload['contactNumber'] ?? null;

        try {
            $client = new PaceApiClient($connection);

            // The payload carries the contact id directly. Only fall back to a
            // shipment read if it's missing — no JobShipment read in the normal path.
            if (empty($contactId) && $shipmentId) {
                $contactId = $client->readJobShipment((string) $shipmentId)['contactNumber'] ?? null;
            }

            if (empty($contactId)) {
                throw new RuntimeException("No contact id in payload for shipment {$shipmentId}");
            }

            $carrierSlugs = $connection->validation_carriers ?: ['smarty', 'ups', 'fedex'];
            $corrected = $this->cleanse($validation, $this->payload, $carrierSlugs);

            // Only act when the address was actually validated. On an API error / no
            // deliverable result, do nothing so the constraint re-fires it later.
            if (! $corrected['validated']) {
                SystemLog::create([
                    'category' => 'integration',
                    'type' => 'pace_address_correction',
                    'level' => 'warning',
                    'loggable_type' => IntegrationConnection::class,
                    'loggable_id' => $connection->id,
                    'status' => 'skipped',
                    'summary' => "Address not validated for Contact {$contactId} — left for retry",
                    'completed_at' => now(),
                    'metadata' => [
                        'job_number' => $jobNumber,
                        'shipment_id' => $shipmentId,
                        'contact_id' => $contactId,
                        'carriers_tried' => array_values($carrierSlugs),
                    ],
                ]);

                return;
            }

            // Diff the validated address against the payload's current values and push
            // ONLY the changed fields (config-driven by the Contact's push-enabled
            // mappings). Pace updateContact merges — verified — so we send just
            // {id + changed fields}, no Contact read.
            $contactObject = $connection->objects()->where('object_name', 'Contact')->first();
            [$changes, $diff] = $this->buildContactChanges($contactObject, $corrected, $this->payload);

            if (! empty($changes)) {
                $client->updateContact(['id' => (int) $contactId] + $changes);
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
                    'job_number' => $jobNumber,
                    'shipment_id' => $shipmentId,
                    'contact_id' => $contactId,
                    'changed_fields' => array_keys($changes),
                    'changes' => $diff,
                    'source' => $corrected['source'],
                    'residential' => $corrected['residential'],
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
                'metadata' => ['job_number' => $jobNumber, 'shipment_id' => $shipmentId, 'contact_id' => $contactId],
            ]);

            throw $e;
        }
    }

    /**
     * Cleanse the source address against the configured validators, trying each in
     * priority order until one returns a usable result. Returns a normalized
     * corrected-address array plus a 'validated' flag (false = API error / no
     * deliverable result).
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
            'input_company' => $source['company'] ?? $source['companyName'] ?? null,
            'input_name' => $source['name'] ?? null,
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
     * Returns [changes (external_field => value), diff (external_field => [from,to])].
     *
     * @param  array<string, mixed>  $corrected
     * @param  array<string, mixed>  $current  The Contact as read from Pace
     * @return array{0: array<string, mixed>, 1: array<string, array{from: mixed, to: mixed}>}
     */
    protected function buildContactChanges(?IntegrationObject $contactObject, array $corrected, array $current): array
    {
        $changes = [];
        $diff = [];
        if (! $contactObject) {
            return [$changes, $diff];
        }

        foreach ($contactObject->fieldMappings()->where('sync_on_push', true)->get() as $mapping) {
            $localKey = $mapping->local_field;
            if (empty($localKey) || ! array_key_exists($localKey, $corrected)) {
                continue;
            }

            $field = $mapping->external_field ?: ltrim((string) $mapping->external_xpath, '@');
            $newValue = $mapping->transformToExternal($corrected[$localKey]);
            $currentValue = $current[$field] ?? null;

            if ($this->valueChanged($newValue, $currentValue)) {
                $changes[$field] = $newValue;
                $diff[$field] = ['from' => $currentValue, 'to' => $newValue];
            }
        }

        return [$changes, $diff];
    }

    protected function valueChanged(mixed $new, mixed $current): bool
    {
        if (is_bool($new) || is_bool($current)) {
            return (bool) $new !== (bool) $current;
        }

        $new = trim((string) $new);
        $current = trim((string) $current);

        if ($new === $current) {
            return false;
        }

        // Never treat a 5-digit ZIP as a change when the current value is that ZIP+4.
        if (preg_match('/^\d{5}$/', $new) && str_starts_with($current, $new.'-')) {
            return false;
        }

        return true;
    }

    /**
     * Recombine a validator's 5-digit ZIP and +4 extension into Pace's ZIP+4 form.
     */
    protected function fullZip(?string $postal, ?string $ext): ?string
    {
        if (empty($postal)) {
            return null;
        }

        return ($ext && ! str_contains($postal, '-')) ? $postal.'-'.$ext : $postal;
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
            'zip' => $this->fullZip($a->output_postal, $a->output_postal_ext) ?? $a->input_postal,
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
