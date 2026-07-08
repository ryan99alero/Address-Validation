<?php

namespace App\Jobs;

use App\Services\Chargebacks\ChargebackEligibility;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After an invoice import, fan out one PushChargeback per eligible charge. A no-op when the master
 * toggle is OFF (records are ignored, not held). Dispatched from the import finalize step, mirroring
 * the carton sync — queued so a Pace call never blocks the import.
 *
 * @param  array<int, int>  $invoiceIds
 */
class PushInvoiceChargebacks implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $invoiceIds
     */
    public function __construct(public array $invoiceIds)
    {
        $this->onQueue('chargebacks');
    }

    public function handle(ChargebackPusher $pusher, ChargebackEligibility $eligibility): void
    {
        if (! $pusher->pushEnabled($pusher->activeConnection())) {
            return; // OFF → ignore this import's charges entirely
        }

        foreach ($eligibility->forInvoices($this->invoiceIds) as $charge) {
            PushChargeback::dispatch((array) $charge);
        }
    }
}
