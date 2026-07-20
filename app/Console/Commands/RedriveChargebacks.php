<?php

namespace App\Console\Commands;

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use App\Services\Chargebacks\ChargebackEligibility;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-drive chargeback ledger rows that landed on a skip disposition — chiefly rows falsely marked
 * `skipped_no_jobshipment` before the resolver was switched to Pace's Carton object. Because those
 * rows already exist in the ledger, the normal import dispatcher (ChargebackEligibility::forInvoices)
 * rejects them as duplicates. This resets each targeted row to `pending` (fresh attempts) and
 * re-dispatches PushChargeback, which now re-resolves the tracking via Carton and either pushes it or
 * records the correct skip (e.g. skipped_job_closed). Claim-first + the [CB:id] token verify make the
 * re-dispatch double-bill-safe.
 */
class RedriveChargebacks extends Command
{
    protected $signature = 'chargebacks:redrive
        {--status=skipped_no_jobshipment : Ledger status to re-drive}
        {--limit=0 : Max rows to re-drive (0 = all)}
        {--dry-run : Report what would be re-driven without changing anything}';

    protected $description = 'Reset skipped chargeback rows to pending and re-dispatch them (re-resolves via Carton)';

    public function handle(ChargebackEligibility $eligibility, ChargebackPusher $pusher): int
    {
        $status = (string) $this->option('status');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (! $pusher->pushEnabled($pusher->activeConnection())) {
            $this->error('Chargeback push is OFF — re-dispatched jobs would no-op and leave rows stuck as pending. Enable the master toggle first.');

            return self::FAILURE;
        }

        $rows = ChargebackPush::query()
            ->where('status', $status)
            ->orderBy('id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No ledger rows with status '{$status}'. Nothing to re-drive.");

            return self::SUCCESS;
        }

        // Rebuild the exact eligibility charge-array for rows that still point at a live charge id.
        $rebuilt = $eligibility
            ->forChargeIds($rows->pluck('carrier_charge_id')->filter()->all())
            ->keyBy('carrier_charge_id');

        $redriven = 0;
        $ineligible = 0;
        foreach ($rows as $row) {
            $charge = $this->chargeFor($row, $rebuilt);
            if ($charge === null) {
                $ineligible++;
                $this->line("  <fg=yellow>skip</> {$row->tracking_number} — charge no longer eligible (deleted/unreconciled/too old); left as {$status}");

                continue;
            }

            if ($dryRun) {
                $redriven++;

                continue;
            }

            $row->update(['status' => ChargebackPush::STATUS_PENDING, 'attempts' => 0, 'last_error' => null]);
            PushChargeback::dispatch($charge);
            $redriven++;
        }

        $verb = $dryRun ? 'Would re-drive' : 'Re-dispatched';
        $this->info("{$verb} {$redriven} row(s) from '{$status}'; {$ineligible} left in place (no longer eligible).");

        return self::SUCCESS;
    }

    /**
     * The charge-array PushChargeback expects. Prefer the freshly-rebuilt eligibility row (authoritative
     * shape, matches the original dedupe_key exactly); fall back to the ledger row's own fields for rows
     * whose carrier_charge_id is null (legacy) or absent from the rebuild.
     *
     * @param  Collection<int, object>  $rebuilt
     * @return array<string, mixed>|null
     */
    private function chargeFor(ChargebackPush $row, Collection $rebuilt): ?array
    {
        if ($row->carrier_charge_id && $rebuilt->has($row->carrier_charge_id)) {
            return (array) $rebuilt->get($row->carrier_charge_id);
        }

        // Charge id present but not returned by eligibility → genuinely no longer eligible; don't guess.
        if ($row->carrier_charge_id) {
            return null;
        }

        // Legacy row without a charge id: rebuild from the ledger. ship_date -> Y-m-d reproduces the
        // stored dedupe_key so the re-dispatched job re-finds THIS row instead of creating a duplicate.
        $invoice = $row->carrier_invoice_id
            ? DB::table('carrier_invoices')->where('id', $row->carrier_invoice_id)->first(['invoice_number', 'invoice_date'])
            : null;

        return [
            'carrier_charge_id' => $row->carrier_charge_id,
            'carrier_id' => $row->carrier_id,
            'carrier_invoice_id' => $row->carrier_invoice_id,
            'invoice_number' => $invoice->invoice_number ?? null,
            'invoice_date' => $invoice->invoice_date ?? null,
            'tracking_number' => $row->tracking_number,
            'charge_category_id' => $row->charge_category_id,
            'driver' => $row->driver,
            'amount' => (float) $row->amount,
            'ship_date' => $row->ship_date?->format('Y-m-d'),
            'activity_code' => $row->activity_code,
        ];
    }
}
