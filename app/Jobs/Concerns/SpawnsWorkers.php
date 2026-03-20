<?php

namespace App\Jobs\Concerns;

use App\Services\WorkerService;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Trait that automatically spawns a queue worker after dispatching a job.
 * Use with dispatch() to ensure workers are available to process the job.
 */
trait SpawnsWorkers
{
    /**
     * Dispatch the job and ensure a worker is running.
     *
     * @return PendingDispatch
     */
    public static function dispatchWithWorker(...$args)
    {
        $dispatch = static::dispatch(...$args);

        // Spawn a worker after dispatching
        app(WorkerService::class)->ensureWorkerRunning();

        return $dispatch;
    }
}
