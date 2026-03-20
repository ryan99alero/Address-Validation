<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class WorkerService
{
    protected string $pidDirectory;

    protected int $maxWorkers;

    protected int $workerTimeout = 3600;

    protected string $memoryLimit = '1G';

    public function __construct()
    {
        $this->pidDirectory = storage_path('app/workers');
        $this->maxWorkers = (int) config('queue.max_auto_workers', 2);
        $this->ensurePidDirectory();
    }

    /**
     * Ensure a worker is running to process jobs.
     * Spawns a new worker if there are pending jobs and no active workers.
     */
    public function ensureWorkerRunning(): void
    {
        $pendingJobs = $this->getPendingJobCount();

        if ($pendingJobs === 0) {
            return;
        }

        $activeWorkers = $this->getActiveWorkerCount();

        if ($activeWorkers >= $this->maxWorkers) {
            Log::channel('worker')->debug('Max workers already running', [
                'active' => $activeWorkers,
                'max' => $this->maxWorkers,
                'pending_jobs' => $pendingJobs,
            ]);

            return;
        }

        $this->spawnWorker();
    }

    /**
     * Spawn a new queue worker process.
     */
    public function spawnWorker(): ?int
    {
        $workerId = uniqid('worker_');
        $pidFile = "{$this->pidDirectory}/{$workerId}.pid";

        $command = sprintf(
            'nohup php -d memory_limit=%s %s/artisan queue:work --stop-when-empty --timeout=%d > %s 2>&1 & echo $!',
            $this->memoryLimit,
            base_path(),
            $this->workerTimeout,
            storage_path("logs/worker_{$workerId}.log")
        );

        $result = Process::run($command);

        if (! $result->successful()) {
            Log::channel('worker')->error('Failed to spawn worker', [
                'worker_id' => $workerId,
                'error' => $result->errorOutput(),
            ]);

            return null;
        }

        $pid = (int) trim($result->output());

        if ($pid <= 0) {
            Log::channel('worker')->error('Invalid PID returned', [
                'worker_id' => $workerId,
                'output' => $result->output(),
            ]);

            return null;
        }

        // Write PID file
        file_put_contents($pidFile, json_encode([
            'pid' => $pid,
            'worker_id' => $workerId,
            'started_at' => now()->toIso8601String(),
            'pending_jobs_at_start' => $this->getPendingJobCount(),
        ]));

        Log::channel('worker')->info('Worker spawned', [
            'worker_id' => $workerId,
            'pid' => $pid,
        ]);

        return $pid;
    }

    /**
     * Get count of pending jobs in the queue.
     */
    public function getPendingJobCount(): int
    {
        return DB::table('jobs')->count();
    }

    /**
     * Get count of active workers.
     */
    public function getActiveWorkerCount(): int
    {
        $count = 0;

        foreach (glob("{$this->pidDirectory}/*.pid") as $pidFile) {
            $data = json_decode(file_get_contents($pidFile), true);
            $pid = $data['pid'] ?? null;

            if ($pid && $this->isProcessRunning($pid)) {
                $count++;
            } else {
                // Clean up stale PID file
                unlink($pidFile);
            }
        }

        return $count;
    }

    /**
     * Check if a process is running.
     */
    protected function isProcessRunning(int $pid): bool
    {
        return posix_getpgid($pid) !== false;
    }

    /**
     * Ensure the PID directory exists.
     */
    protected function ensurePidDirectory(): void
    {
        if (! is_dir($this->pidDirectory)) {
            mkdir($this->pidDirectory, 0755, true);
        }
    }

    /**
     * Clean up completed workers and their log files.
     */
    public function cleanupCompletedWorkers(): int
    {
        $cleaned = 0;

        foreach (glob("{$this->pidDirectory}/*.pid") as $pidFile) {
            $data = json_decode(file_get_contents($pidFile), true);
            $pid = $data['pid'] ?? null;
            $workerId = $data['worker_id'] ?? null;

            if (! $pid || ! $this->isProcessRunning($pid)) {
                unlink($pidFile);
                $cleaned++;

                // Optionally clean up old log file (keep for debugging)
                $logFile = storage_path("logs/worker_{$workerId}.log");
                if ($workerId && file_exists($logFile)) {
                    $age = time() - filemtime($logFile);
                    // Delete log files older than 1 day
                    if ($age > 86400) {
                        unlink($logFile);
                    }
                }

                Log::channel('worker')->info('Cleaned up completed worker', [
                    'worker_id' => $workerId,
                    'pid' => $pid,
                ]);
            }
        }

        return $cleaned;
    }

    /**
     * Get status information about all workers.
     *
     * @return array<array{worker_id: string, pid: int, started_at: string, is_running: bool}>
     */
    public function getWorkerStatus(): array
    {
        $workers = [];

        foreach (glob("{$this->pidDirectory}/*.pid") as $pidFile) {
            $data = json_decode(file_get_contents($pidFile), true);
            $pid = $data['pid'] ?? null;

            $workers[] = [
                'worker_id' => $data['worker_id'] ?? basename($pidFile, '.pid'),
                'pid' => $pid,
                'started_at' => $data['started_at'] ?? 'unknown',
                'is_running' => $pid ? $this->isProcessRunning($pid) : false,
            ];
        }

        return $workers;
    }
}
