<?php

namespace App\Console\Commands;

use App\Jobs\PushInvoiceChargebacks;
use App\Models\CarrierInvoice;
use App\Services\Chargebacks\ChargebackEligibility;
use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Console\Command;

/**
 * Dispatch the chargeback backlog for invoices that were imported while the push toggle was OFF.
 *
 * Chargebacks normally fan out at import-finalize (dispatchPostImportJobs -> PushInvoiceChargebacks),
 * but that job no-ops when chargeback_push_enabled is false — so charges imported during an OFF window
 * are never recorded or pushed, and flipping the toggle ON later does NOT retro-process them. This
 * command re-runs the same import path (PushInvoiceChargebacks -> ChargebackEligibility::forInvoices)
 * over already-imported recent + reconciled invoices, so eligible charges dispatch through the Carton
 * resolver. Charges already in the ledger (incl. the skipped_no_jobshipment leftovers) are rejected by
 * forInvoices — re-drive those with chargebacks:redrive instead.
 */
class DispatchChargebacks extends Command
{
    protected $signature = 'chargebacks:dispatch
        {--chunk=250 : Invoice ids per PushInvoiceChargebacks job}
        {--dry-run : Report the eligible backlog without dispatching}';

    protected $description = 'Dispatch the chargeback backlog for invoices imported while push was OFF (recent + reconciled)';

    public function handle(ChargebackEligibility $eligibility, ChargebackPusher $pusher): int
    {
        if (! $pusher->pushEnabled($pusher->activeConnection())) {
            $this->error('Chargeback push is OFF — enable chargeback_push_enabled on the Pace connection first.');

            return self::FAILURE;
        }

        $cutoff = CartonCostSyncService::recentInvoiceCutoff();
        $invoiceIds = CarrierInvoice::query()
            ->where('charges_reconciled', true)
            ->where('invoice_date', '>=', $cutoff)
            ->pluck('id')
            ->all();

        if ($invoiceIds === []) {
            $this->info('No recent reconciled invoices to dispatch.');

            return self::SUCCESS;
        }

        $eligible = $eligibility->forInvoices($invoiceIds);
        $this->info(sprintf(
            '%d recent reconciled invoices (since %s); %d eligible charges not yet in the ledger, $%s.',
            count($invoiceIds), $cutoff->toDateString(), $eligible->count(), number_format($eligible->sum('amount'), 2)
        ));
        foreach ($eligible->groupBy('driver')->map->count() as $driver => $n) {
            $this->line("  {$driver}: {$n}");
        }

        if ($eligible->isEmpty()) {
            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run — nothing dispatched. Re-run without --dry-run to enqueue.');

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $jobs = 0;
        foreach (array_chunk($invoiceIds, $chunk) as $batch) {
            PushInvoiceChargebacks::dispatch($batch);
            $jobs++;
        }

        $this->info("Enqueued {$jobs} PushInvoiceChargebacks job(s) on the chargebacks queue. Monitor via the ChargebackPushes view.");

        return self::SUCCESS;
    }
}
