<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\Carrier;
use App\Models\IntegrationConnection;
use App\Models\IntegrationObject;
use App\Models\ShipViaCode;
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
 * fields}, verified safe — config-driven by the object's field mappings.
 * Before pushing, the shipment is verified still Planned (JobShipment/@planned);
 * one that has moved on (e.g. created already Shipped) is left untouched. On an
 * actual correction we also set JobShipment/@u_addressCorrected = true — an
 * update, so it does not re-fire the punch-out (which is gated on record
 * creation, not updates). Before/after values are recorded for the correction audit.
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

        // CSR + salesperson ride along in the webhook payload (Pace Connect output),
        // so we capture them with no extra Pace reads.
        $csr = trim((string) ($this->payload['csr'] ?? $this->payload['csrName'] ?? '')) ?: null;
        $salesPerson = trim((string) ($this->payload['salesPerson'] ?? $this->payload['sales_person'] ?? $this->payload['salesPersonName'] ?? '')) ?: null;

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
                        'csr' => $csr,
                        'sales_person' => $salesPerson,
                        'carriers_tried' => array_values($carrierSlugs),
                    ],
                ]);

                return;
            }

            // Residential is authoritative from the live FedEx validation (always re-checked). Treat the
            // shipment as residential if EITHER FedEx says so OR Pace already had the flag set — and when
            // it is, always FLAG residential on the push.
            $residentialFinal = $this->toBool($this->payload['residential'] ?? null) || ($corrected['residential'] === true);
            if ($residentialFinal) {
                $corrected['residential'] = true;
            }

            // Diff the validated address against the payload's current values and push
            // ONLY the changed fields (config-driven by the Contact's push-enabled
            // mappings). Pace updateContact merges — verified — so we send just
            // {id + changed fields}, no Contact read.
            $contactObject = $connection->objects()->where('object_name', 'Contact')->first();
            [$changes, $diff] = $this->buildContactChanges($contactObject, $corrected, $this->payload);

            // Residential FedEx Ground → Home Delivery swap. Reuses the BestWay matcher to land the
            // Home Delivery ship-via that keeps the SAME plant, payer, and account (plant lives on our
            // ship_via_codes, not Pace). Null when not residential, not a FedEx Ground ship-via, or no
            // Home Delivery equivalent exists (e.g. UPS Ground — residential is a surcharge there, not a
            // separate service).
            $homeSwap = $residentialFinal ? $this->resolveHomeDeliverySwap((string) ($this->payload['ship_via'] ?? '')) : null;

            // JobShipment guard: only touch a shipment that is still Planned (JobShipment/@planned ==
            // true). One that has moved on must not be corrected OR re-routed. Checked when there's work
            // to do — an address/residential change OR a ship-via swap. Anything but an explicit true
            // (including an unreadable shipment) blocks the write.
            $hasWork = ! empty($changes) || $homeSwap !== null;
            $shipmentPlanned = $hasWork ? $this->shipmentIsPlanned($client, $this->payload, $shipmentId) : null;
            $plannedBlocked = $hasWork && $shipmentPlanned !== true;

            // Shadow / dry-run mode: validate and log what WOULD change, but do not
            // write anything back to Pace.
            $dryRun = (bool) $connection->dry_run;
            // Explicit "we pushed a correction back to Pace" flag — recorded on the audit entry so
            // pushes are queryable directly (metadata->pushed) instead of string-matching the summary.
            $pushed = ! empty($changes) && ! $plannedBlocked && ! $dryRun;
            $swapped = $homeSwap !== null && ! $plannedBlocked && ! $dryRun;
            $addressCorrectedFlagged = false;

            if ($pushed) {
                $client->updateContact(['id' => (int) $contactId] + $changes);
            }

            // One JobShipment write (partial merge) for the corrected flag + the Ground→Home Delivery
            // ship-via swap. An UPDATE, so it never re-fires the punch-out (gated on record creation).
            // Best-effort: a failed shipment write must not fail a correction that already landed.
            if (($pushed || $swapped) && ! empty($shipmentId)) {
                $shipmentUpdate = ['id' => (int) $shipmentId];
                if ($pushed) {
                    $shipmentUpdate['u_addressCorrected'] = true;
                }
                if ($swapped) {
                    $shipmentUpdate['shipVia'] = (int) $homeSwap->code;
                }
                try {
                    $client->updateJobShipment($shipmentUpdate);
                    $addressCorrectedFlagged = $pushed;
                } catch (Throwable) {
                    $swapped = false;
                    $addressCorrectedFlagged = false;
                }
            }

            // Audit snapshot: original as received, and corrected = original with ONLY
            // the pushed changes applied (so the view highlights exactly what moved).
            $originalSnapshot = [
                'name' => $this->payload['name'] ?? null,
                'company' => $this->payload['company'] ?? null,
                'address1' => $this->payload['address1'] ?? null,
                'address2' => $this->payload['address2'] ?? null,
                'city' => $this->payload['city'] ?? null,
                'state' => $this->payload['state'] ?? null,
                'zip' => $this->payload['zip'] ?? null,
                'country' => $this->payload['country'] ?? null,
            ];
            $correctedSnapshot = $originalSnapshot;
            foreach ($diff as $field => $fromTo) {
                $correctedSnapshot[$field] = $fromTo['to'] ?? null;
            }
            // The corrected address is the CASS-standard answer — uppercase it (the
            // original stays as received). Name/company/zip are left untouched.
            foreach (['address1', 'address2', 'city', 'state', 'country'] as $field) {
                if (! empty($correctedSnapshot[$field])) {
                    $correctedSnapshot[$field] = mb_strtoupper((string) $correctedSnapshot[$field]);
                }
            }

            SystemLog::create([
                'category' => 'integration',
                'type' => 'pace_address_correction',
                'level' => 'info',
                'loggable_type' => IntegrationConnection::class,
                'loggable_id' => $connection->id,
                'status' => $plannedBlocked ? 'skipped' : 'success',
                'summary' => match (true) {
                    $plannedBlocked => "Pace correction NOT pushed — JobShipment {$shipmentId} is not Planned (Contact {$contactId})",
                    $dryRun && $hasWork => "DRY RUN — would correct/re-route Contact {$contactId} (nothing pushed)",
                    empty($changes) && ! $swapped => "Pace address validated (no changes) for Contact {$contactId}",
                    $swapped && $pushed => "Pace address corrected & ship-via swapped to Home Delivery for Contact {$contactId}",
                    $swapped => "Residential — ship-via swapped to Home Delivery for Contact {$contactId}",
                    default => "Pace address corrected & pushed for Contact {$contactId}",
                },
                'completed_at' => now(),
                'metadata' => [
                    'job_number' => $jobNumber,
                    'shipment_id' => $shipmentId,
                    'contact_id' => $contactId,
                    'csr' => $csr,
                    'sales_person' => $salesPerson,
                    'dry_run' => $dryRun,
                    'pushed' => $pushed,
                    'pushed_at' => $pushed ? now()->toIso8601String() : null,
                    'shipment_planned' => $shipmentPlanned,
                    'planned_blocked' => $plannedBlocked,
                    'address_corrected_flagged' => $addressCorrectedFlagged,
                    'residential_final' => $residentialFinal,
                    'ship_via_in' => $this->payload['ship_via'] ?? null,
                    'ship_via_swap' => $homeSwap?->code,
                    'ship_via_swap_service' => $homeSwap?->service_name,
                    'swapped' => $swapped,
                    'changed_fields' => array_keys($changes),
                    'changes' => $diff,
                    'original' => $originalSnapshot,
                    'corrected' => $correctedSnapshot,
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
                'metadata' => ['job_number' => $jobNumber, 'shipment_id' => $shipmentId, 'contact_id' => $contactId, 'csr' => $csr, 'sales_person' => $salesPerson],
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
        // Real-time Connect corrections ALWAYS re-validate against the carrier API — never short-circuit
        // on a cached answer. The address may have changed (e.g. a ZIP update) since it was cached, and
        // a cache hit carries no residential classification, so serving it would push a null residential
        // and wipe Pace's flag. The API result still refreshes the cache for the batch/invoice paths.
        $validation->useLocalCache(false);

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
     * The FedEx Home Delivery ship-via to swap a residential FedEx Ground shipment to — same plant,
     * payer, and account as the original (reuses the BestWay matcher; plant lives on our ship_via_codes,
     * resolved from the code). Null when the incoming ship-via isn't a resolvable FedEx Ground, or there
     * is no Home Delivery equivalent (e.g. UPS Ground — its residential handling is a surcharge, not a
     * separate service).
     */
    protected function resolveHomeDeliverySwap(string $shipViaCode): ?ShipViaCode
    {
        if (trim($shipViaCode) === '') {
            return null;
        }

        $original = ShipViaCode::lookup($shipViaCode);
        if ($original === null || $original->carrier_slug !== 'fedex' || $original->service_type !== 'FEDEX_GROUND') {
            return null;
        }

        $home = ShipViaCode::findMatchingForBestWay('GROUND_HOME_DELIVERY', $original->plant_id, $original);

        return ($home !== null && $home->code !== $original->code) ? $home : null;
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

            // Never push a null (unknown) value over Pace's existing one — e.g. a residential flag we
            // couldn't classify must not wipe Pace's. Safety net alongside the always-API cleanse.
            if ($corrected[$localKey] === null) {
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

    /**
     * Whether the shipment is still Planned in Pace (JobShipment/@planned == true). Prefers the
     * webhook payload when it already carries `planned` (no Pace read); otherwise reads the
     * JobShipment by id. Returns null when it can't be determined — no shipment id, an unreadable
     * shipment, or a missing field — so the caller treats anything but an explicit true as "do not
     * push". Failures are swallowed here (never a hard error) — the constraint re-fires later.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function shipmentIsPlanned(PaceApiClient $client, array $payload, mixed $shipmentId): ?bool
    {
        if (array_key_exists('planned', $payload)) {
            return $this->toBool($payload['planned']);
        }

        if (empty($shipmentId)) {
            return null;
        }

        try {
            $shipment = $client->readJobShipment((string) $shipmentId);
        } catch (Throwable) {
            return null;
        }

        return array_key_exists('planned', $shipment) ? $this->toBool($shipment['planned']) : null;
    }

    protected function valueChanged(mixed $new, mixed $current): bool
    {
        // Boolean-ish fields (e.g. residential): normalize both sides, so a string
        // "false" / "0" / "no" from the payload reads as false, not true.
        if (is_bool($new) || is_bool($current)) {
            return $this->toBool($new) !== $this->toBool($current);
        }

        $new = trim((string) $new);
        $current = trim((string) $current);

        // Case-insensitive: standardizing case alone is not a "change".
        if (strcasecmp($new, $current) === 0) {
            return false;
        }

        // Never treat a 5-digit ZIP as a change when the current value is that ZIP+4.
        if (preg_match('/^\d{5}$/', $new) && str_starts_with($current, $new.'-')) {
            return false;
        }

        return true;
    }

    /**
     * Normalize a boolean-ish value (true/false, "true"/"false", 1/0, "yes"/"no").
     */
    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 't', 'y'], true);
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
        // The corrected (good) address is emitted in CASS-standard UPPERCASE; the
        // abbreviation (Drive→DR, Suite→STE) is already done upstream by the carrier
        // or the cache's normalize(), so this just enforces the capitalization.
        return [
            'address1' => $this->upper($a->output_address_1 ?? $a->input_address_1),
            'address2' => $this->upper($a->output_address_2 ?? $a->input_address_2),
            'address3' => null,
            'city' => $this->upper($a->output_city ?? $a->input_city),
            'state' => $this->upper($a->output_state ?? $a->input_state),
            'zip' => $this->fullZip($a->output_postal, $a->output_postal_ext) ?? $a->input_postal,
            'postal_ext' => $a->output_postal_ext,
            'country' => $this->upper($a->output_country ?? $a->input_country),
            'residential' => $a->is_residential,
            'corrected' => $a->output_address_1 !== null && $a->output_address_1 !== $a->input_address_1,
            'source' => $a->validation_source,
        ];
    }

    /**
     * Uppercase a value for CASS-standard output (null/empty stays null).
     */
    protected function upper(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtoupper($value);
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
