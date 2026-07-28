<?php

namespace App\Console\Commands;

use App\Models\CartonCost;
use App\Models\CorrectedAddress;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resolves Job / Customer ID+Name / CSR / Sales Rep for the correction cache's most-corrected bad
 * addresses via a live Pace Carton lookup (date-scoped so a recycled tracking resolves to the right
 * shipment), persisting the result onto carton_costs. Only bad addresses corrected at least
 * "Correction Cache Minimum Pace Lookup" times (the Pace connection setting, default 5) are looked
 * up — a live Pace call per address is costly. Re-runnable: already-enriched trackings are skipped.
 */
class PaceLookupCorrectionCache extends Command
{
    protected $signature = 'correction-cache:pace-lookup
        {--min= : Times-corrected threshold (default: the Pace connection setting, else 5)}
        {--limit=0 : Max variants to look up (0 = all qualifying)}
        {--dry-run : List what would be looked up without calling Pace}';

    protected $description = 'Pace Carton lookup of job/customer/CSR/sales rep for the most-corrected bad addresses';

    public function handle(): int
    {
        $connection = IntegrationConnection::byDriver(IntegrationConnection::DRIVER_PACE)->active()->first();
        if ($connection === null) {
            $this->error('No active Pace connection.');

            return self::FAILURE;
        }

        $min = $this->option('min') !== null ? (int) $this->option('min') : (int) ($connection->correction_cache_min_lookup ?? 5);
        $min = max(1, $min);
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // Real "Times Corrected" = the number of address-correction lines on carrier invoices for a
        // given bad address (not the validation-cache counter). Gather every still-usable bad address
        // corrected >= $min times, newest tracking + reference date in hand for the Pace lookup.
        $targets = [];
        CorrectedAddress::query()
            ->whereHas('invoiceLines')
            ->with(['variants' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(300, function ($addresses) use (&$targets, $min): void {
                foreach ($addresses as $address) {
                    $activeHashes = $address->variants->keyBy('input_hash');
                    foreach ($address->correctionOccurrencesByHash() as $hash => $occ) {
                        if ($occ['count'] < $min || $occ['tracking'] === null || ! $activeHashes->has($hash)) {
                            continue;
                        }
                        $targets[] = ['tracking' => $occ['tracking'], 'date' => $occ['date'], 'count' => $occ['count']];
                    }
                }
            });

        usort($targets, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        if ($limit > 0) {
            $targets = array_slice($targets, 0, $limit);
        }

        $this->info('Correction-cache Pace lookup: '.count($targets)." bad address(es) corrected >= {$min} times.");

        $client = new PaceApiClient($connection);
        $pusher = new ChargebackPusher;
        $done = $skip = $miss = $fail = 0;

        foreach ($targets as $target) {
            $tracking = $target['tracking'];
            $occ = $target;

            $carton = CartonCost::firstOrNew(['tracking_number' => $tracking]);
            if ($carton->exists && $carton->pace_customer_name) {
                $skip++; // already enriched

                continue;
            }

            if ($dryRun) {
                $done++;
                $this->line("  would look up {$tracking} (ref date ".($occ['date'] ?? '—').", corrected {$target['count']}x)");

                continue;
            }

            try {
                $shipment = ChargebackPusher::repShipment($pusher->lookupJobShipments($client, $tracking, $occ['date']));
                if ($shipment === null) {
                    $miss++;

                    continue;
                }
                $enrich = ChargebackPusher::enrichmentFrom($shipment);

                if (! $carton->exists) {
                    $carton->ship_date = $occ['date'];
                }
                $carton->pace_job_number = $carton->pace_job_number ?: $this->clean($shipment['job'] ?? null);
                $carton->pace_customer_id = $carton->pace_customer_id ?: $enrich['pace_customer_id'];
                $carton->pace_customer_name = $enrich['pace_customer_name'];
                $carton->pace_csr_name = $enrich['pace_csr_name'];
                $carton->pace_salesperson_name = $enrich['pace_salesperson_name'];
                $carton->synced_at = now();
                $carton->save();
                $done++;

                usleep(150000); // gentle on the Pace API between lookups
            } catch (Throwable $e) {
                $fail++;
                $this->line("  <fg=red>fail</> {$tracking} — ".$e->getMessage());
            }
        }

        $verb = $dryRun ? 'Would look up' : 'Looked up';
        $this->info("{$verb}: {$done}, skipped (no tracking / already enriched): {$skip}, not in Pace: {$miss}, failed: {$fail}.");

        return self::SUCCESS;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
