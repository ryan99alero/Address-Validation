<?php

namespace App\Console\Commands;

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crash/timeout recovery. A create that timed out leaves the ledger row `pending`/`unverified` — we
 * don't know if Pace saved it. This sweeps those rows and asks Pace whether the [CB:id] token
 * already exists: found → adopt the JobCost id (it applied); not found → re-dispatch the push (safe
 * to re-post). Never blind-retries a create. Scheduled every few minutes.
 */
class ReconcileChargebacks extends Command
{
    protected $signature = 'chargebacks:reconcile {--minutes=5 : Only rows untouched for at least this many minutes}';

    protected $description = 'Resolve stuck chargeback pushes by verifying against Pace (anti-double-bill)';

    public function handle(): int
    {
        $connection = IntegrationConnection::byDriver(IntegrationConnection::DRIVER_PACE)->active()->first();
        if (! $connection) {
            $this->warn('No active Pace connection.');

            return self::SUCCESS;
        }
        $client = new PaceApiClient($connection);

        $rows = ChargebackPush::whereIn('status', [ChargebackPush::STATUS_PENDING, ChargebackPush::STATUS_UNVERIFIED])
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        $adopted = 0;
        $reposted = 0;
        foreach ($rows as $row) {
            if (($existingId = $client->findJobCostIdByToken('[CB:'.$row->id.']')) !== null) {
                $row->update(['status' => ChargebackPush::STATUS_PUSHED, 'pace_jobcost_id' => $existingId, 'pushed_at' => now()]);
                $adopted++;

                continue;
            }

            PushChargeback::dispatch($this->snapshot($row));
            $reposted++;
        }

        $this->info("Reconciled {$rows->count()} stuck rows: {$adopted} adopted (already applied), {$reposted} re-dispatched.");

        return self::SUCCESS;
    }

    /**
     * Rebuild the charge snapshot from the ledger row so PushChargeback can re-run without the
     * original charge row (which may have been deleted/recreated).
     *
     * @return array<string, mixed>
     */
    private function snapshot(ChargebackPush $row): array
    {
        $invoice = $row->carrier_invoice_id ? DB::table('carrier_invoices')->where('id', $row->carrier_invoice_id)->first() : null;

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
            'ship_date' => $row->ship_date?->toDateString(),
            'activity_code' => $row->activity_code,
        ];
    }
}
