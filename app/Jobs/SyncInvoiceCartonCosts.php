<?php

namespace App\Jobs;

use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After an invoice import, read the Pace carton ship cost for each of the invoice's tracking
 * numbers (via the Pace API) into the recoup baseline mirror. Dispatched from the import's
 * finalize step so recoup (invoiced total − ship cost) has its baseline as soon as charges land.
 * Queued because it makes a live Pace API call — it must not block the import.
 */
class SyncInvoiceCartonCosts implements ShouldQueue
{
    use Queueable;

    // A batch invoice can carry thousands of tracking numbers (hundreds of invoices), so the
    // Pace carton pull runs many sequential loadValueObjects calls — well past the default 60s.
    public int $timeout = 900;

    /**
     * @param  array<int, int>  $invoiceIds
     */
    public function __construct(public array $invoiceIds) {}

    public function handle(CartonCostSyncService $sync): void
    {
        if ($this->invoiceIds === []) {
            return;
        }

        // Only recent invoices are recoupable — skip carton sync for old bulk/historical imports.
        // (A years-old FedEx batch carries thousands of trackings that time the sync out and match
        // nothing current anyway.)
        $recentInvoiceIds = CarrierInvoice::whereIn('id', $this->invoiceIds)
            ->where('invoice_date', '>=', CartonCostSyncService::recentInvoiceCutoff())
            ->pluck('id')
            ->all();

        if ($recentInvoiceIds === []) {
            return;
        }

        $trackingNumbers = CarrierCharge::query()
            ->whereIn('carrier_invoice_id', $recentInvoiceIds)
            ->whereNotNull('tracking_number')
            ->distinct()
            ->pluck('tracking_number')
            ->all();

        $sync->syncTrackings($trackingNumbers);
    }
}
