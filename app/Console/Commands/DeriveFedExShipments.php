<?php

namespace App\Console\Commands;

use App\Models\CarrierInvoice;
use App\Services\Invoices\FedExShipmentDeriveService;
use Illuminate\Console\Command;

/**
 * Backfills per-shipment cost data on carrier_shipments from charges:
 *  - FedEx invoices → derive the (missing) shipment rows.
 *  - UPS invoices   → enrich existing PDF shipment rows with the base/fee split.
 * Safe to re-run.
 */
class DeriveFedExShipments extends Command
{
    protected $signature = 'shipments:backfill-costs
        {--carrier=all : fedex | ups | all}
        {--invoice= : a single carrier_invoices.id}';

    protected $description = 'Backfill per-shipment cost data on carrier_shipments from charges';

    public function handle(FedExShipmentDeriveService $service): int
    {
        foreach (['fedex', 'ups'] as $slug) {
            if (! in_array($this->option('carrier'), [$slug, 'all'], true)) {
                continue;
            }

            $query = CarrierInvoice::query()->whereHas('carrier', fn ($q) => $q->where('slug', $slug));
            if ($invoiceId = $this->option('invoice')) {
                $query->whereKey($invoiceId);
            }

            $total = $query->count();
            $this->info(strtoupper($slug).": {$total} invoice(s)…");
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $affected = 0;
            $query->orderBy('id')->chunkById(200, function ($invoices) use ($service, $slug, &$affected, $bar): void {
                foreach ($invoices as $invoice) {
                    $affected += $slug === 'fedex'
                        ? $service->deriveForInvoice($invoice)
                        : $service->enrichCostsForInvoice($invoice);
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine(2);
            $this->info(strtoupper($slug).' done: '.($slug === 'fedex' ? "{$affected} rows derived" : "{$affected} rows enriched").'.');
        }

        return self::SUCCESS;
    }
}
