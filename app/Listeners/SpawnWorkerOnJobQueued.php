<?php

namespace App\Listeners;

use App\Services\WorkerService;
use Illuminate\Queue\Events\JobQueued;

class SpawnWorkerOnJobQueued
{
    public function __construct(
        protected WorkerService $workerService
    ) {}

    /**
     * Handle the JobQueued event.
     * Automatically spawns a worker when a job is queued.
     */
    public function handle(JobQueued $event): void
    {
        // Only spawn workers if auto-spawn is enabled
        if (! config('queue.auto_spawn_workers', true)) {
            return;
        }

        $this->workerService->ensureWorkerRunning();
    }
}
