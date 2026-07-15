<?php

namespace App\Services\Recoup;

use App\Models\CarrierCharge;
use App\Models\CartonCost;
use App\Models\IntegrationConnection;
use App\Services\Integrations\PaceApiClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Populates the local carton_costs mirror from the Pace "Carton" object (via the Pace REST API),
 * so recoup can join costs locally instead of hitting Pace per tracking. Two entry points:
 *
 *   upsert(rows)   — the source-agnostic core: give it carton rows, it writes them.
 *   syncFromPace() — pull cartons from the active Pace integration for the tracking numbers we
 *                    have charges for, and upsert them.
 *
 * The Pace Carton object exposes cost / trackingNumber / actualDateTime / shipment. Job and
 * customer are mapped through $paceFieldMap so the exact Pace field names stay configurable.
 */
class CartonCostSyncService
{
    /**
     * Logical field => Pace xpath selector on the Carton object (loadValueObjects field
     * descriptors). Job and customer traverse the carton's shipment -> job reference. Pace fields
     * carry two names (API vs SQL); these are the API xpaths: the ship-date selector is
     * "actualDate" (the SQL name "actualDateTime" is rejected here), and the shipment/job/customer
     * traversal is relative with no leading slash.
     *
     * @var array<string, string>
     */
    protected array $paceFieldMap = [
        'tracking_number' => '@trackingNumber',
        'ship_cost' => '@cost',
        'ship_date' => '@actualDate',
        'pace_job_number' => 'shipment/job/@job',
        'pace_customer_id' => 'shipment/job/@customer',
        // Third-party billing is a shipment-level flag; read it off the carton's
        // master JobShipment (same traversal as job/customer).
        'is_third_party' => 'shipment/@thirdPartyCharges',
    ];

    /**
     * Upsert carton rows into the local mirror, stamping synced_at. Rows are keyed by the
     * logical carton fields; unknown keys are ignored.
     *
     * UPS recycles tracking numbers, so Pace can hold several cartons per number across years.
     * We keep only the most recent (latest ship_date) per tracking — the current shipment;
     * older ones are recycle collisions belonging to different jobs/customers.
     *
     * @param  iterable<int, array{tracking_number:string, ship_cost?:float|string|null, ship_date?:string|null, pace_job_number?:string|null, pace_customer_id?:string|null}>  $rows
     * @return int number of distinct tracking numbers written
     */
    public function upsert(iterable $rows): int
    {
        $now = now();

        /** @var array<string, array<string, mixed>> $byTracking */
        $byTracking = [];

        foreach ($rows as $row) {
            $tracking = trim((string) ($row['tracking_number'] ?? ''));
            if ($tracking === '') {
                continue;
            }

            $shipDate = $row['ship_date'] ?? null;
            if ($shipDate instanceof \DateTimeInterface) {
                $shipDate = $shipDate->format('Y-m-d');
            } elseif ($shipDate !== null) {
                $shipDate = (string) $shipDate;
            }

            // Keep the latest ship_date per tracking (null sorts lowest). ISO dates compare
            // correctly as strings.
            if (isset($byTracking[$tracking]) && ($byTracking[$tracking]['ship_date'] ?? '') >= ($shipDate ?? '')) {
                continue;
            }

            $byTracking[$tracking] = [
                'tracking_number' => $tracking,
                'ship_cost' => round((float) ($row['ship_cost'] ?? 0), 2),
                'ship_date' => $shipDate,
                'pace_job_number' => $row['pace_job_number'] ?? null,
                'pace_customer_id' => $row['pace_customer_id'] ?? null,
                'is_third_party' => $this->interpretThirdParty($row['is_third_party'] ?? null),
                'synced_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($byTracking === []) {
            return 0;
        }

        foreach (array_chunk(array_values($byTracking), 500) as $chunk) {
            CartonCost::upsert(
                $chunk,
                ['tracking_number'],
                ['ship_cost', 'ship_date', 'pace_job_number', 'pace_customer_id', 'is_third_party', 'synced_at', 'updated_at'],
            );
        }

        // New cartons change recoup coverage — drop its cached aggregate.
        Cache::forget(RecoupService::COVERAGE_CACHE_KEY);

        return count($byTracking);
    }

    /**
     * Recoup only applies to recent shipments (you can't bill a customer for a years-old
     * invoice), so carton sync is limited to invoices billed within this window. This also keeps
     * bulk historical imports (huge FedEx batch PDFs spanning 2018+) from dispatching enormous
     * carton syncs that time out and match nothing current.
     */
    public const RECENT_INVOICE_MONTHS = 6;

    public static function recentInvoiceCutoff(): CarbonInterface
    {
        return now()->subMonths(self::RECENT_INVOICE_MONTHS)->startOfDay();
    }

    /**
     * Tracking numbers on RECENT invoices that carry carrier charges but aren't in the carton
     * mirror yet — the set worth pulling from Pace.
     *
     * @return array<int, string>
     */
    public function pendingTrackingNumbers(): array
    {
        return CarrierCharge::query()
            ->whereNotNull('tracking_number')
            ->whereNotIn('tracking_number', CartonCost::query()->select('tracking_number'))
            ->whereExists(fn ($q) => $q->from('carrier_invoices')
                ->whereColumn('carrier_invoices.id', 'carrier_charges.carrier_invoice_id')
                ->where('carrier_invoices.invoice_date', '>=', self::recentInvoiceCutoff()))
            ->distinct()
            ->pluck('tracking_number')
            ->all();
    }

    /**
     * Pull carton costs for every pending tracking number (has charges, no carton yet) from
     * the active Pace integration. Returns the number written, or null if no active Pace
     * connection exists. Used by the manual backfill command.
     */
    public function syncFromPace(?PaceApiClient $client = null, int $chunk = 100): ?int
    {
        return $this->syncTrackings($this->pendingTrackingNumbers(), $client, $chunk);
    }

    /**
     * Pull carton costs for a specific set of tracking numbers from the active Pace integration
     * and upsert them — the import-time path (read cartons for the invoice just imported).
     * Returns the number written, 0 if there's nothing to pull, or null if no active Pace
     * connection exists.
     *
     * @param  array<int, string>  $trackingNumbers
     */
    public function syncTrackings(array $trackingNumbers, ?PaceApiClient $client = null, int $chunk = 100): ?int
    {
        $trackingNumbers = array_values(array_unique(array_filter(
            $trackingNumbers,
            fn ($t): bool => trim((string) $t) !== '',
        )));

        if ($trackingNumbers === []) {
            return 0;
        }

        $client ??= $this->resolvePaceClient();
        if (! $client) {
            return null;
        }

        $fields = [];
        foreach ($this->paceFieldMap as $logical => $xpath) {
            $fields[] = ['name' => $logical, 'xpath' => $xpath];
        }

        $written = 0;
        try {
            foreach (array_chunk($trackingNumbers, $chunk) as $batch) {
                $filter = collect($batch)
                    ->map(fn (string $t): string => "@trackingNumber = '".str_replace("'", "''", $t)."'")
                    ->implode(' or ');

                $response = $client->loadValueObjects(
                    objectName: 'Carton',
                    fields: $fields,
                    // A recycled tracking can return several cartons across years; allow headroom
                    // so none are truncated (upsert keeps the latest per tracking).
                    xpathFilter: $filter,
                    limit: count($batch) * 10,
                );

                $rows = $client->parseValueObjects($response['valueObjects'] ?? [])
                    ->map(fn (array $vo): array => $this->mapCartonRow($vo));

                $written += $this->upsert($rows);
            }
        } catch (\Throwable $e) {
            // Carton sync is best-effort — a Pace outage or a misconfigured field xpath must
            // never fail the import that dispatched this. Log and return what we managed.
            Log::error('Carton cost sync from Pace failed', ['error' => $e->getMessage(), 'written' => $written]);
        }

        return $written;
    }

    /**
     * Map a parsed Pace Carton value object (keyed by the logical field names in $paceFieldMap)
     * into a carton_costs row.
     *
     * @param  array<string, mixed>  $vo
     * @return array{tracking_number:?string, ship_cost:mixed, ship_date:?string, pace_job_number:?string, pace_customer_id:?string, is_third_party:mixed}
     */
    public function mapCartonRow(array $vo): array
    {
        $shipDate = $vo['ship_date'] ?? null;
        if ($shipDate instanceof Carbon) {
            $shipDate = $shipDate->toDateString();
        }

        return [
            'tracking_number' => $vo['tracking_number'] ?? null,
            'ship_cost' => $vo['ship_cost'] ?? 0,
            'ship_date' => $shipDate,
            'pace_job_number' => $vo['pace_job_number'] ?? null,
            'pace_customer_id' => $vo['pace_customer_id'] ?? null,
            'is_third_party' => $vo['is_third_party'] ?? null,
        ];
    }

    /**
     * Interpret Pace's thirdPartyCharges value into a boolean, or null when unknown
     * (empty/unrecognized) so the caller can fall back to the base-charge heuristic.
     * Tolerant of boolean, "true"/"false", "Y"/"N", 1/0, or a non-zero dollar amount.
     */
    protected function interpretThirdParty(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        $s = strtolower(trim((string) $value));
        if (in_array($s, ['1', 'true', 'yes', 'y', 't'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'n', 'f'], true)) {
            return false;
        }
        if (is_numeric($s)) {
            return (float) $s !== 0.0; // a non-zero third-party charge total ⇒ third-party
        }

        return null;
    }

    protected function resolvePaceClient(): ?PaceApiClient
    {
        $connection = IntegrationConnection::query()
            ->byDriver(IntegrationConnection::DRIVER_PACE)
            ->active()
            ->first();

        return $connection ? new PaceApiClient($connection) : null;
    }
}
