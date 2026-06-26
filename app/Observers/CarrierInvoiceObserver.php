<?php

namespace App\Observers;

use App\Jobs\RebuildCarrierRollup;
use App\Models\CarrierInvoice;

class CarrierInvoiceObserver
{
    /**
     * A new invoice adds charges to the reporting tables, so queue a rebuild.
     */
    public function created(CarrierInvoice $invoice): void
    {
        $this->queueRebuild();
    }

    /**
     * A deleted invoice cascade-deletes its carrier_charges, so the rollups are
     * now stale — queue a full rebuild (which simply re-derives from current data,
     * no reversal entries needed).
     */
    public function deleted(CarrierInvoice $invoice): void
    {
        $this->queueRebuild();
    }

    private function queueRebuild(): void
    {
        // Short delay so a bulk import/purge settles before the (deduplicated)
        // rebuild fires.
        RebuildCarrierRollup::dispatch()->delay(now()->addSeconds(60));
    }
}
