<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\SystemLog;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Console\Command;
use Throwable;

class BackfillPaceReps extends Command
{
    protected $signature = 'pace:backfill-reps {--overwrite : Re-fetch even corrections that already have a CSR/salesperson}';

    protected $description = 'Backfill CSR + salesperson on existing Pace corrections by reading each Job from Pace';

    public function handle(): int
    {
        $connection = IntegrationConnection::where('driver', 'pace')->first();
        if (! $connection) {
            $this->error('No Pace integration connection found.');

            return self::FAILURE;
        }

        $client = new PaceApiClient($connection);

        $logs = SystemLog::query()
            ->where('type', 'pace_address_correction')
            ->whereNotNull('metadata->job_number')
            ->when(! $this->option('overwrite'), fn ($q) => $q->whereNull('metadata->csr'))
            ->get();

        $byJob = $logs->groupBy(fn (SystemLog $log): string => (string) ($log->metadata['job_number'] ?? ''));
        $updated = 0;
        $failed = 0;

        foreach ($byJob as $jobNumber => $group) {
            if ($jobNumber === '') {
                continue;
            }

            try {
                $reps = $client->jobReps($jobNumber);
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Job {$jobNumber}: {$e->getMessage()}");

                continue;
            }

            foreach ($group as $log) {
                $metadata = $log->metadata;
                $metadata['csr'] = $reps['csr'];
                $metadata['sales_person'] = $reps['sales_person'];
                $log->update(['metadata' => $metadata]);
                $updated++;
            }

            $this->line(sprintf('%s → CSR %s, Salesperson %s', $jobNumber, $reps['csr'] ?? '—', $reps['sales_person'] ?? '—'));
        }

        $this->info("Backfilled {$updated} correction(s) across {$byJob->count()} job(s); {$failed} job(s) failed.");

        return self::SUCCESS;
    }
}
