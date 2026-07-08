<?php

namespace App\Console\Commands;

use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Console\Command;

/**
 * One-shot live test of the chargeback create path against a test job/tracking (safe while Pace's
 * Create-Costs is off): JobShipment lookup → openJob gate → JobCost payload → createObject →
 * read the new record back. Does NOT touch the ledger or import flow.
 */
class TestChargebackCreate extends Command
{
    protected $signature = 'chargebacks:test-create
        {--tracking=TEST061522TRACK}
        {--amount=20.20}
        {--activity=72510}
        {--label=Address Correction fee}';

    protected $description = 'Create one test JobCost chargeback in Pace and read it back';

    public function handle(ChargebackPusher $pusher): int
    {
        $connection = $pusher->activeConnection();
        if (! $connection) {
            $this->error('No active Pace connection.');

            return self::FAILURE;
        }
        $client = new PaceApiClient($connection);

        $tracking = (string) $this->option('tracking');
        $this->info("Looking up JobShipment for {$tracking}…");
        $shipments = $pusher->lookupJobShipments($client, $tracking);
        if ($shipments === []) {
            $this->error('No JobShipment found — would be skipped_no_jobshipment.');

            return self::FAILURE;
        }
        if (count($shipments) > 1) {
            $this->warn(count($shipments).' shipments matched (recycled tracking) — real path would disambiguate by ship date.');
        }
        $s = $shipments[0];
        $this->line('  job='.($s['job'] ?? '?').' jobPart='.($s['jobPart'] ?? '?').' customer='.($s['customer'] ?? '-').' openJob='.var_export($s['openJob'] ?? null, true));

        if (($s['openJob'] ?? null) !== true) {
            $this->error('Job is not open — would be skipped_job_closed. Aborting test.');

            return self::FAILURE;
        }

        $notes = $pusher->buildNotes(0, [
            'carrier' => 'UPS',
            'label' => (string) $this->option('label'),
            'tracking' => $tracking,
            'invoice' => 'TEST-INV',
            'invoice_date' => now()->toDateString(),
            'amount' => (float) $this->option('amount'),
            'recorded' => '450 FARABEE DR S, LAFAYETTE IN 47905',
            'corrected' => '313 FARABEE DR S, LAFAYETTE IN 47905',
        ]);

        $payload = $pusher->buildJobCostPayload([
            'job' => (string) $s['job'],
            'jobPart' => (string) ($s['jobPart'] ?? '01'),
            'activityCode' => (string) $this->option('activity'),
            'amount' => (float) $this->option('amount'),
            'tracking' => $tracking,
            'notes' => $notes,
        ]);

        $this->info('Creating JobCost with payload:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));

        $result = $client->createObject('JobCost', $payload);
        $newId = $result['id'] ?? $result['primaryKey'] ?? null;
        $this->info('Created JobCost id: '.var_export($newId, true));

        if ($newId) {
            $readBack = $client->readObject('JobCost', (string) $newId);
            $this->info('Read-back confirms:');
            foreach (['id', 'job', 'jobPart', 'activityCode', 'cost', 'actualCost', 'sourceID', 'notes', 'postedDate', 'startDateTime'] as $f) {
                $this->line(sprintf('  %-14s = %s', $f, $readBack[$f] ?? '(none)'));
            }
        }

        return self::SUCCESS;
    }
}
