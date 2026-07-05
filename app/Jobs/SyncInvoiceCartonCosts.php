<?php

namespace App\Jobs;

use App\Models\CarrierCharge;
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

    /**
     * @param  array<int, int>  $invoiceIds
     */
    public function __construct(public array $invoiceIds) {}

    public function handle(CartonCostSyncService $sync): void
    {
        if ($this->invoiceIds === []) {
            return;
        }

        $trackingNumbers = CarrierCharge::query()
            ->whereIn('carrier_invoice_id', $this->invoiceIds)
            ->whereNotNull('tracking_number')
            ->distinct()
            ->pluck('tracking_number')
            ->all();

        $sync->syncTrackings($trackingNumbers);
    }
}
