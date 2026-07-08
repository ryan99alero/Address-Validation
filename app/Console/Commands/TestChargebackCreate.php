<?php

namespace App\Console\Commands;

use App\Models\ChargebackPush;
use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot live test of the FULL chargeback path against a test job/tracking (safe while Pace's
 * Create-Costs is off): JobShipment lookup → openJob gate → claim-first ledger row → JobCost create
 * → confirm the ledger with the returned id + snapshot. Creates BOTH lines of an address correction
 * (the fee → 72510 and its fuel → 72520), so ADV shows two rows marked pushed.
 */
class TestChargebackCreate extends Command
{
    protected $signature = 'chargebacks:test-create {--tracking=TEST061522TRACK}';

    protected $description = 'Create the test address-correction chargebacks (fee + fuel) through the ledger';

    public function handle(ChargebackPusher $pusher): int
    {
        $connection = $pusher->activeConnection();
        if (! $connection) {
            $this->error('No active Pace connection.');

            return self::FAILURE;
        }
        $client = new PaceApiClient($connection);
        $tracking = (string) $this->option('tracking');
        $carrierId = (int) DB::table('carriers')->where('name', 'like', '%UPS%')->value('id');

        $this->info("Looking up JobShipment for {$tracking}…");
        $shipments = $pusher->lookupJobShipments($client, $tracking);
        if ($shipments === []) {
            $this->error('No JobShipment found — skipped_no_jobshipment.');

            return self::FAILURE;
        }
        $s = $shipments[0];
        $this->line('  job='.($s['job'] ?? '?').' jobPart='.($s['jobPart'] ?? '?').' openJob='.var_export($s['openJob'] ?? null, true));
        if (($s['openJob'] ?? null) !== true) {
            $this->error('Job not open — skipped_job_closed.');

            return self::FAILURE;
        }

        // The two lines an address correction produces: the flat fee and its fuel.
        $lines = [
            ['activity' => '72510', 'amount' => 20.20, 'label' => 'Address Correction fee'],
            ['activity' => '72520', 'amount' => 2.62, 'label' => 'Address Correction fuel surcharge'],
        ];

        foreach ($lines as $line) {
            $dedupe = 'TEST|'.$tracking.'|'.$line['activity'].'|'.$line['amount'];

            // Claim-first: the row exists BEFORE the Pace call; the unique key is the mutex.
            $ledger = ChargebackPush::firstOrCreate(['dedupe_key' => $dedupe], [
                'carrier_id' => $carrierId, 'tracking_number' => $tracking, 'driver' => 'address_correction',
                'amount' => $line['amount'], 'activity_code' => $line['activity'],
                'pace_job' => $s['job'] ?? null, 'pace_job_part' => $s['jobPart'] ?? null,
                'pace_customer_id' => $s['customer'] ?? null, 'status' => ChargebackPush::STATUS_PENDING,
            ]);

            if ($ledger->status === ChargebackPush::STATUS_PUSHED) {
                $this->warn("  {$line['label']}: already pushed (JobCost {$ledger->pace_jobcost_id}) — idempotency held, skipping.");

                continue;
            }

            $notes = $pusher->buildNotes($ledger->id, [
                'carrier' => 'UPS', 'label' => $line['label'], 'tracking' => $tracking,
                'invoice' => 'TEST-INV', 'invoice_date' => now()->toDateString(), 'amount' => $line['amount'],
                'recorded' => '450 FARABEE DR S, LAFAYETTE IN 47905', 'corrected' => '313 FARABEE DR S, LAFAYETTE IN 47905',
            ]);
            $payload = $pusher->buildJobCostPayload([
                'job' => (string) $s['job'], 'jobPart' => (string) ($s['jobPart'] ?? '01'),
                'activityCode' => $line['activity'], 'amount' => $line['amount'], 'tracking' => $tracking, 'notes' => $notes,
            ]);

            $result = $client->createObject('JobCost', $payload);
            $jobCostId = $result['id'] ?? $result['primaryKey'] ?? null;

            $ledger->update([
                'notes' => $notes, 'pace_jobcost_id' => $jobCostId, 'response_snapshot' => $result,
                'status' => ChargebackPush::STATUS_PUSHED, 'pushed_at' => now(), 'attempts' => $ledger->attempts + 1,
            ]);

            $this->info("  {$line['label']}: JobCost {$jobCostId} → activityCode {$line['activity']} \${$line['amount']}  (ledger #{$ledger->id} = pushed)");
        }

        $this->newLine();
        $this->info('Ledger rows for this tracking:');
        foreach (ChargebackPush::where('tracking_number', $tracking)->get() as $r) {
            $this->line(sprintf('  #%d  %-8s  act=%s  $%s  JobCost=%s  %s', $r->id, $r->status, $r->activity_code, $r->amount, $r->pace_jobcost_id ?? '-', $r->pushed_at));
        }

        return self::SUCCESS;
    }
}
