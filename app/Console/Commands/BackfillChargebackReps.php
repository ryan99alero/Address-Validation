<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill customer / CSR / salesperson names onto existing chargeback ledger rows by re-running the
 * Carton lookup (read-only — no JobCost is created). Same resolution the live push now does; this just
 * enriches rows created before the fields existed, so the closed-job download is complete for history.
 */
class BackfillChargebackReps extends Command
{
    protected $signature = 'chargebacks:backfill-reps
        {--carrier= : Carrier slug to limit to (e.g. fedex, ups)}
        {--year= : Only rows whose carrier invoice is dated this year}
        {--limit=0 : Max rows to process (0 = all)}
        {--overwrite : Re-fetch even rows that already have a customer/CSR/salesperson name}';

    protected $description = 'Backfill Pace customer/CSR/salesperson names on existing chargeback pushes (read-only Carton lookup)';

    public function handle(ChargebackPusher $pusher): int
    {
        $connection = IntegrationConnection::where('driver', 'pace')->first();
        if (! $connection) {
            $this->error('No Pace integration connection found.');

            return self::FAILURE;
        }
        $client = new PaceApiClient($connection);

        $carrier = null;
        $query = ChargebackPush::query()->whereNotNull('tracking_number');

        if ($slug = $this->option('carrier')) {
            $carrier = Carrier::where('slug', $slug)->first();
            if (! $carrier) {
                $this->error("Unknown carrier slug: {$slug}");

                return self::FAILURE;
            }
            $query->where('carrier_id', $carrier->id);
        }

        if ($year = $this->option('year')) {
            $invoiceIds = CarrierInvoice::query()
                ->when($carrier, fn ($q) => $q->where('carrier_id', $carrier->id))
                ->whereYear('invoice_date', (int) $year)
                ->pluck('id');
            $query->whereIn('carrier_invoice_id', $invoiceIds);
        }

        if (! $this->option('overwrite')) {
            $query->whereNull('pace_csr_name')->whereNull('pace_customer_name')->whereNull('pace_salesperson_name');
        }

        $limit = (int) $this->option('limit');
        $matched = (clone $query)->count();
        $total = $limit > 0 ? min($matched, $limit) : $matched;
        if ($total === 0) {
            $this->info('No chargeback rows match — nothing to backfill.');

            return self::SUCCESS;
        }
        $this->info("Backfilling {$total} chargeback row(s)…");

        // Resolve invoice dates once so a row with no ship_date still narrows a recycled tracking correctly.
        $invoiceDates = CarrierInvoice::query()
            ->whereIn('id', (clone $query)->whereNotNull('carrier_invoice_id')->distinct()->pluck('carrier_invoice_id'))
            ->pluck('invoice_date', 'id');

        $updated = 0;
        $noData = 0;
        $failed = 0;
        $processed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // chunkById manages its own ordering/limit, so cap via the callback (return false) rather than
        // a query ->limit(), which would conflict with the keyset paging.
        $query->chunkById(200, function ($rows) use ($pusher, $client, $invoiceDates, $limit, &$updated, &$noData, &$failed, &$processed, $bar): bool {
            foreach ($rows as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
                $processed++;

                try {
                    $reference = $row->ship_date?->format('Y-m-d')
                        ?? ($row->carrier_invoice_id ? optional($invoiceDates[$row->carrier_invoice_id] ?? null)->format('Y-m-d') : null);

                    $shipments = $pusher->lookupJobShipments($client, (string) $row->tracking_number, $reference);
                    $rep = ChargebackPusher::repShipment($shipments);

                    if ($rep === null) {
                        $noData++;
                        $bar->advance();

                        continue;
                    }

                    $enrichment = ChargebackPusher::enrichmentFrom($rep);
                    $row->update($enrichment);
                    $updated += array_filter($enrichment) === [] ? 0 : 1;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("cb#{$row->id} ({$row->tracking_number}): {$e->getMessage()}");
                }
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Enriched {$updated}, no Pace match {$noData}, failed {$failed} of {$total}.");

        return self::SUCCESS;
    }
}
