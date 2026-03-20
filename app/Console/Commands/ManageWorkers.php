<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ManageWorkers extends Command
{
    protected $signature = 'workers:manage
        {action : Action to perform (start|cleanup|status)}
        {--timeout=3600 : Worker timeout in seconds}
        {--memory=1G : Memory limit for worker}
        {--stale-minutes=30 : Minutes before a worker is considered stale}';

    protected $description = 'Manage queue workers: start on-demand workers, cleanup stale processes, or check status';

    protected string $pidDirectory;

    public function __construct()
    {
        parent::__construct();
        $this->pidDirectory = storage_path('app/workers');
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'start' => $this->startWorker(),
            'cleanup' => $this->cleanupStaleWorkers(),
            'status' => $this->showStatus(),
            default => $this->error("Unknown action: {$action}") ?? 1,
        };
    }

    /**
     * Start an on-demand worker that stops when queue is empty.
     */
    protected function startWorker(): int
    {
        $this->ensurePidDirectory();

        $timeout = $this->option('timeout');
        $memory = $this->option('memory');
        $workerId = uniqid('worker_');
        $pidFile = "{$this->pidDirectory}/{$workerId}.pid";

        $command = sprintf(
            'php -d memory_limit=%s %s/artisan queue:work --stop-when-empty --timeout=%d 2>&1',
            escapeshellarg($memory),
            base_path(),
            $timeout
        );

        $this->info("Starting worker: {$workerId}");
        $this->line("Command: {$command}");

        // Start process in background
        $process = Process::start($command);

        // Write PID file
        $pid = $process->id();
        file_put_contents($pidFile, json_encode([
            'pid' => $pid,
            'started_at' => now()->toIso8601String(),
            'command' => $command,
        ]));

        Log::channel('worker')->info('Worker started', [
            'worker_id' => $workerId,
            'pid' => $pid,
            'timeout' => $timeout,
            'memory' => $memory,
        ]);

        $this->info("Worker {$workerId} started with PID {$pid}");
        $this->line("PID file: {$pidFile}");

        return 0;
    }

    /**
     * Clean up stale/zombie workers.
     */
    protected function cleanupStaleWorkers(): int
    {
        $this->ensurePidDirectory();

        $staleMinutes = (int) $this->option('stale-minutes');
        $cleaned = 0;
        $killed = 0;

        // First, clean up PID files for workers that no longer exist
        foreach (glob("{$this->pidDirectory}/*.pid") as $pidFile) {
            $data = json_decode(file_get_contents($pidFile), true);
            $pid = $data['pid'] ?? null;
            $startedAt = isset($data['started_at']) ? Carbon::parse($data['started_at']) : null;

            if (! $pid) {
                unlink($pidFile);
                $cleaned++;

                continue;
            }

            // Check if process is still running
            $isRunning = $this->isProcessRunning($pid);

            if (! $isRunning) {
                // Process ended naturally, clean up PID file
                unlink($pidFile);
                $cleaned++;

                Log::channel('worker')->info('Cleaned up finished worker', [
                    'pid_file' => basename($pidFile),
                    'pid' => $pid,
                ]);

                continue;
            }

            // Check if process is stale (running too long)
            if ($startedAt && $startedAt->diffInMinutes(now()) > $staleMinutes) {
                $this->warn("Killing stale worker PID {$pid} (running for {$startedAt->diffInMinutes(now())} minutes)");

                // Kill the stale process
                posix_kill($pid, SIGTERM);
                sleep(2);

                // Force kill if still running
                if ($this->isProcessRunning($pid)) {
                    posix_kill($pid, SIGKILL);
                }

                unlink($pidFile);
                $killed++;

                Log::channel('worker')->warning('Killed stale worker', [
                    'pid' => $pid,
                    'started_at' => $data['started_at'],
                    'minutes_running' => $startedAt->diffInMinutes(now()),
                ]);
            }
        }

        // Also check for any orphaned PHP queue:work processes
        $orphaned = $this->findOrphanedWorkers();
        if (count($orphaned) > 0) {
            $this->warn('Found '.count($orphaned).' orphaned queue:work processes');

            foreach ($orphaned as $pid) {
                $this->line("  - PID {$pid}");
            }

            if ($this->confirm('Kill orphaned workers?', false)) {
                foreach ($orphaned as $pid) {
                    posix_kill($pid, SIGTERM);
                    $killed++;

                    Log::channel('worker')->warning('Killed orphaned worker', ['pid' => $pid]);
                }
            }
        }

        $this->info("Cleanup complete: {$cleaned} cleaned, {$killed} killed");

        return 0;
    }

    /**
     * Show current worker status.
     */
    protected function showStatus(): int
    {
        $this->ensurePidDirectory();

        $this->info('=== Queue Worker Status ===');
        $this->newLine();

        // Show tracked workers
        $pidFiles = glob("{$this->pidDirectory}/*.pid");
        $this->info('Tracked Workers: '.count($pidFiles));

        foreach ($pidFiles as $pidFile) {
            $data = json_decode(file_get_contents($pidFile), true);
            $pid = $data['pid'] ?? 'unknown';
            $startedAt = $data['started_at'] ?? 'unknown';
            $isRunning = is_numeric($pid) ? $this->isProcessRunning($pid) : false;

            $status = $isRunning ? '<fg=green>RUNNING</>' : '<fg=red>STOPPED</>';
            $this->line("  [{$status}] PID {$pid} - Started: {$startedAt}");
        }

        $this->newLine();

        // Show all queue:work processes
        $allWorkers = $this->findAllWorkers();
        $this->info('All Queue Workers: '.count($allWorkers));

        foreach ($allWorkers as $worker) {
            $this->line("  - PID {$worker['pid']} ({$worker['cpu']}% CPU, {$worker['memory']} memory)");
        }

        $this->newLine();

        // Show jobs in queue
        $pendingJobs = \DB::table('jobs')->count();
        $this->info("Pending Jobs: {$pendingJobs}");

        return 0;
    }

    /**
     * Check if a process is running.
     */
    protected function isProcessRunning(int $pid): bool
    {
        return posix_getpgid($pid) !== false;
    }

    /**
     * Find all queue:work processes.
     *
     * @return array<array{pid: int, cpu: string, memory: string}>
     */
    protected function findAllWorkers(): array
    {
        $result = Process::run('ps aux | grep "queue:work" | grep -v grep');

        if (! $result->successful() || empty($result->output())) {
            return [];
        }

        $workers = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            if (empty($line)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 11) {
                $workers[] = [
                    'pid' => (int) $parts[1],
                    'cpu' => $parts[2],
                    'memory' => $parts[3].'%',
                ];
            }
        }

        return $workers;
    }

    /**
     * Find orphaned workers (running but not tracked).
     *
     * @return array<int>
     */
    protected function findOrphanedWorkers(): array
    {
        $allWorkers = $this->findAllWorkers();
        $trackedPids = [];

        foreach (glob("{$this->pidDirectory}/*.pid") as $pidFile) {
            $data = json_decode(file_get_contents($pidFile), true);
            if (isset($data['pid'])) {
                $trackedPids[] = (int) $data['pid'];
            }
        }

        $orphaned = [];
        foreach ($allWorkers as $worker) {
            if (! in_array($worker['pid'], $trackedPids)) {
                $orphaned[] = $worker['pid'];
            }
        }

        return $orphaned;
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
}
