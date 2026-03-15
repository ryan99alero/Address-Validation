<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstallSupervisor extends Command
{
    protected $signature = 'deploy:supervisor
                            {--install : Actually install the config (requires sudo)}
                            {--user= : User to run the worker as}
                            {--procs=2 : Number of worker processes}
                            {--memory=512 : Memory limit in MB per worker}
                            {--php= : Path to PHP binary}
                            {--show : Just display the generated config}';

    protected $description = 'Generate and optionally install Supervisor configuration for queue workers';

    public function handle(): int
    {
        // Check OS
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->warn('Supervisor is typically used on Linux servers.');
            $this->info('For macOS development, use: php artisan queue:work');
            $this->newLine();
            $this->info('Generating config anyway for reference...');
            $this->newLine();
        }

        $config = $this->generateConfig();

        if ($this->option('show') || ! $this->option('install')) {
            $this->displayConfig($config);

            if (! $this->option('install')) {
                $this->newLine();
                $this->info('To install this config on a Linux server, run:');
                $this->line('  php artisan deploy:supervisor --install --user=www-data');
            }

            return self::SUCCESS;
        }

        return $this->installConfig($config);
    }

    protected function generateConfig(): string
    {
        $stubPath = base_path('deploy/supervisor/queue-worker.conf.stub');

        if (! File::exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");

            return '';
        }

        $stub = File::get($stubPath);

        $appName = Str::slug(config('app.name', 'laravel'));
        $user = $this->option('user') ?? get_current_user();
        $phpPath = $this->option('php') ?? $this->detectPhpPath();
        $appPath = base_path();
        $logPath = storage_path('logs');
        $numProcs = $this->option('procs');
        $memoryLimit = $this->option('memory');

        $replacements = [
            '{{APP_NAME}}' => $appName,
            '{{PHP_PATH}}' => $phpPath,
            '{{APP_PATH}}' => $appPath,
            '{{USER}}' => $user,
            '{{NUM_PROCS}}' => $numProcs,
            '{{MEMORY_LIMIT}}' => $memoryLimit,
            '{{LOG_PATH}}' => $logPath,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    protected function detectPhpPath(): string
    {
        // Try to find PHP path
        $paths = [
            PHP_BINARY,
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return 'php';
    }

    protected function displayConfig(string $config): void
    {
        $this->info('Generated Supervisor Configuration:');
        $this->newLine();
        $this->line('─────────────────────────────────────────────────────────');
        $this->line($config);
        $this->line('─────────────────────────────────────────────────────────');
    }

    protected function installConfig(string $config): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->error('Supervisor installation is only supported on Linux.');
            $this->info('Use --show to view the config for manual installation.');

            return self::FAILURE;
        }

        // Check if supervisor is installed
        exec('which supervisorctl 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            $this->error('Supervisor is not installed.');
            $this->info('Install it with: sudo apt install supervisor');

            return self::FAILURE;
        }

        $appName = Str::slug(config('app.name', 'laravel'));
        $configPath = "/etc/supervisor/conf.d/{$appName}-worker.conf";

        // Check if we can write to the directory
        if (! is_writable('/etc/supervisor/conf.d')) {
            $this->error('Cannot write to /etc/supervisor/conf.d/');
            $this->info('You may need to run this command with sudo:');
            $this->line("  sudo php artisan deploy:supervisor --install --user={$this->option('user')}");

            // Output config so user can manually install
            $this->newLine();
            $this->info('Or manually create the config file:');
            $this->line("  sudo nano {$configPath}");
            $this->newLine();
            $this->displayConfig($config);

            return self::FAILURE;
        }

        // Write the config
        File::put($configPath, $config);
        $this->info("Config written to: {$configPath}");

        // Reload supervisor
        $this->info('Reloading Supervisor...');
        exec('supervisorctl reread', $output);
        exec('supervisorctl update', $output);

        $this->info('Supervisor configuration installed successfully!');
        $this->newLine();
        $this->info('Useful commands:');
        $this->line("  supervisorctl status {$appName}-worker:*");
        $this->line("  supervisorctl restart {$appName}-worker:*");
        $this->line("  supervisorctl stop {$appName}-worker:*");

        return self::SUCCESS;
    }
}
