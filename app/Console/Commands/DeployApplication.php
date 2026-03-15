<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployApplication extends Command
{
    protected $signature = 'deploy:install
                            {--fresh : Run fresh migrations (DESTRUCTIVE - drops all tables)}
                            {--seed : Run database seeders}
                            {--supervisor : Install Supervisor config (Linux only)}
                            {--user=www-data : User for Supervisor workers}
                            {--skip-migrations : Skip running migrations}
                            {--skip-optimize : Skip optimization steps}';

    protected $description = 'Run all deployment steps for the application';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔═══════════════════════════════════════════════════╗');
        $this->info('║       Address Validation - Deployment Script       ║');
        $this->info('╚═══════════════════════════════════════════════════╝');
        $this->newLine();

        $steps = [
            'Checking environment' => fn () => $this->checkEnvironment(),
            'Running migrations' => fn () => $this->runMigrations(),
            'Running seeders' => fn () => $this->runSeeders(),
            'Caching configuration' => fn () => $this->cacheConfig(),
            'Setting up Supervisor' => fn () => $this->setupSupervisor(),
            'Final checks' => fn () => $this->finalChecks(),
        ];

        foreach ($steps as $name => $callback) {
            $this->info("→ {$name}...");
            $result = $callback();

            if ($result === false) {
                $this->error("  ✗ {$name} failed!");

                return self::FAILURE;
            }

            if ($result === 'skipped') {
                $this->comment('  ⊘ Skipped');
            } else {
                $this->info('  ✓ Done');
            }
        }

        $this->newLine();
        $this->info('╔═══════════════════════════════════════════════════╗');
        $this->info('║            Deployment completed!                   ║');
        $this->info('╚═══════════════════════════════════════════════════╝');
        $this->newLine();

        $this->displayPostDeployInfo();

        return self::SUCCESS;
    }

    protected function checkEnvironment(): bool|string
    {
        // Check .env exists
        if (! file_exists(base_path('.env'))) {
            $this->error('  .env file not found!');
            $this->line('  Copy .env.example to .env and configure it.');

            return false;
        }

        // Check APP_KEY
        if (empty(config('app.key'))) {
            $this->warn('  APP_KEY not set. Generating...');
            $this->call('key:generate', ['--force' => true]);
        }

        // Check database connection
        try {
            \DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->error('  Database connection failed: '.$e->getMessage());

            return false;
        }

        return true;
    }

    protected function runMigrations(): bool|string
    {
        if ($this->option('skip-migrations')) {
            return 'skipped';
        }

        if ($this->option('fresh')) {
            if (app()->isProduction()) {
                $this->error('  Cannot run fresh migrations in production!');

                return false;
            }

            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $this->call('migrate', ['--force' => true]);
        }

        return true;
    }

    protected function runSeeders(): bool|string
    {
        if (! $this->option('seed') && ! $this->option('fresh')) {
            return 'skipped';
        }

        $this->call('db:seed', ['--force' => true]);

        return true;
    }

    protected function cacheConfig(): bool|string
    {
        if ($this->option('skip-optimize')) {
            return 'skipped';
        }

        // Only cache in production
        if (! app()->isProduction()) {
            $this->comment('  Skipping cache in non-production environment');

            return 'skipped';
        }

        $this->call('config:cache');
        $this->call('route:cache');
        $this->call('view:cache');
        $this->call('event:cache');

        return true;
    }

    protected function setupSupervisor(): bool|string
    {
        if (! $this->option('supervisor')) {
            // Just show the config for reference
            $this->call('deploy:supervisor', ['--show' => true]);

            return 'skipped';
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->comment('  Supervisor setup is only available on Linux');

            return 'skipped';
        }

        $this->call('deploy:supervisor', [
            '--install' => true,
            '--user' => $this->option('user'),
        ]);

        return true;
    }

    protected function finalChecks(): bool|string
    {
        // Check storage link
        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }

        // Ensure directories are writable
        $directories = [
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('app/private/imports'),
            storage_path('app/private/exports'),
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        return true;
    }

    protected function displayPostDeployInfo(): void
    {
        $this->info('Next steps:');
        $this->newLine();

        if (PHP_OS_FAMILY === 'Linux') {
            $this->line('  1. Start queue workers:');
            $this->line('     supervisorctl start '.config('app.name').'-worker:*');
            $this->newLine();
        } else {
            $this->line('  1. Start queue worker (development):');
            $this->line('     php artisan queue:work --tries=3 --timeout=3600');
            $this->newLine();
        }

        $this->line('  2. Configure carriers in the admin panel:');
        $this->line('     '.config('app.url').'/carriers');
        $this->newLine();

        $this->line('  3. Create an admin user if needed:');
        $this->line('     php artisan make:filament-user');
        $this->newLine();
    }
}
