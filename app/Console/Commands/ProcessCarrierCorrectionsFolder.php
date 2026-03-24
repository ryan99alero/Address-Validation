<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCarrierInvoiceFile;
use App\Models\CarrierInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ProcessCarrierCorrectionsFolder extends Command
{
    protected $signature = 'invoices:process-folder
                            {path : Root folder containing carrier subfolders (UPS/, FedEx/)}
                            {--dry-run : Show what would be processed without dispatching jobs}
                            {--sync : Process synchronously instead of dispatching jobs}
                            {--limit= : Limit number of files to process}';

    protected $description = 'Recursively process carrier invoice files from a folder structure (Carrier/Year/files)';

    public function handle(): int
    {
        $rootPath = $this->argument('path');
        $dryRun = $this->option('dry-run');
        $sync = $this->option('sync');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if (! File::isDirectory($rootPath)) {
            $this->error("Directory does not exist: {$rootPath}");

            return self::FAILURE;
        }

        $this->info("Scanning for invoice files in: {$rootPath}");

        // Find all CSV files recursively
        $files = $this->findCsvFiles($rootPath);
        $totalFiles = count($files);

        $this->info("Found {$totalFiles} CSV file(s)");

        if ($limit) {
            $files = array_slice($files, 0, $limit);
            $this->info("Limiting to {$limit} files");
        }

        $dispatched = 0;
        $skipped = 0;
        $alreadyProcessed = 0;

        $progressBar = $this->output->createProgressBar(count($files));
        $progressBar->start();

        foreach ($files as $filePath) {
            $progressBar->advance();

            // Detect carrier from path
            $carrierSlug = $this->detectCarrierFromPath($filePath);
            if (! $carrierSlug) {
                $skipped++;

                continue;
            }

            // Check if already processed
            if (CarrierInvoice::isFileProcessed($filePath)) {
                $alreadyProcessed++;

                continue;
            }

            if ($dryRun) {
                $this->newLine();
                $this->line('  Would process: '.basename($filePath)." ({$carrierSlug})");
                $dispatched++;

                continue;
            }

            if ($sync) {
                // Process synchronously
                ProcessCarrierInvoiceFile::dispatchSync($filePath, $carrierSlug);
            } else {
                // Dispatch to queue
                ProcessCarrierInvoiceFile::dispatch($filePath, $carrierSlug);
            }

            $dispatched++;
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total files found', $totalFiles],
                ['Already processed', $alreadyProcessed],
                ['Skipped (unknown carrier)', $skipped],
                [$dryRun ? 'Would dispatch' : 'Jobs dispatched', $dispatched],
            ]
        );

        if (! $dryRun && ! $sync && $dispatched > 0) {
            $this->newLine();
            $this->warn('Jobs dispatched to queue. Make sure queue worker is running:');
            $this->line('  php artisan queue:work --queue=default');
            $this->newLine();
            $this->info('Monitor progress with:');
            $this->line('  php artisan queue:monitor default');
            $this->line('  Or view in Telescope: /telescope/jobs');
        }

        return self::SUCCESS;
    }

    /**
     * Find all CSV files recursively in a directory.
     */
    protected function findCsvFiles(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if ($extension === 'csv') {
                    $files[] = $file->getPathname();
                }
            }
        }

        // Sort by filename for consistent processing order
        sort($files);

        return $files;
    }

    /**
     * Detect carrier from file path (looks for UPS or FedEx in path).
     */
    protected function detectCarrierFromPath(string $filePath): ?string
    {
        $pathLower = strtolower($filePath);

        if (str_contains($pathLower, '/ups/') || str_contains($pathLower, '\\ups\\')) {
            return 'ups';
        }

        if (str_contains($pathLower, '/fedex/') || str_contains($pathLower, '\\fedex\\')) {
            return 'fedex';
        }

        // Fallback: check filename
        $filename = strtolower(basename($filePath));

        if (str_contains($filename, 'ups')) {
            return 'ups';
        }

        if (str_contains($filename, 'fedex') || str_contains($filename, 'fed_ex')) {
            return 'fedex';
        }

        // UPS account number pattern in filename
        if (preg_match('/^[0-9E][0-9A-Z]{3,}/', $filename)) {
            return 'ups';
        }

        return null;
    }
}
