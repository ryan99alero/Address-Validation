<?php

namespace App\Console\Commands;

use App\Models\CarrierInvoice;
use App\Services\Invoices\FedExShipmentDeriveService;
use Illuminate\Console\Command;

/**
 * Backfills derived per-shipment rows (carrier_shipments) for FedEx invoices from
 * their charges, so the Per-Shipment Costs view renders. Safe to re-run.
 */
class DeriveFedExShipments extends Command
{
    protected $signature = 'shipments:derive-fedex
        {--invoice= : a single carrier_invoices.id to (re)derive}
        {--only-missing : skip invoices that already have derived shipments}';

    protected $description = 'Derive carrier_shipments for FedEx invoices from their charges';

    public function handle(FedExShipmentDeriveService $service): int
    {
        $query = CarrierInvoice::query()
            ->whereHas('carrier', fn ($q) => $q->where('slug', 'fedex'));

        if ($invoiceId = $this->option('invoice')) {
            $query->whereKey($invoiceId);
        }

        if ($this->option('only-missing')) {
            $query->whereDoesntHave('shipments', fn ($q) => $q->where('source_type', FedExShipmentDeriveService::SOURCE));
        }

        $total = $query->count();
        $this->info("Deriving shipments for {$total} FedEx invoice(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $shipments = 0;
        $query->orderBy('id')->chunkById(200, function ($invoices) use ($service, &$shipments, $bar): void {
            foreach ($invoices as $invoice) {
                $shipments += $service->deriveForInvoice($invoice);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$shipments} shipment rows derived.");

        return self::SUCCESS;
    }
}
