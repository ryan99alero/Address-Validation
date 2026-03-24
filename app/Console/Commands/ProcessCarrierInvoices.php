<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ProcessCarrierInvoices extends Command
{
    protected $signature = 'invoices:process
                            {--input= : Input directory path (overrides config)}
                            {--archive= : Archive directory path (overrides config)}
                            {--dry-run : Show what would be processed without actually processing}';

    protected $description = 'Process carrier invoice files from input directory and archive after processing';

    protected string $inputPath;

    protected string $archivePath;

    public function handle(CarrierInvoiceParserService $parserService): int
    {
        $this->inputPath = $this->option('input') ?? config('services.carrier_invoices.input_path', storage_path('invoices/input'));
        $this->archivePath = $this->option('archive') ?? config('services.carrier_invoices.archive_path', storage_path('invoices/processed'));

        // Ensure directories exist
        if (! File::isDirectory($this->inputPath)) {
            $this->error("Input directory does not exist: {$this->inputPath}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($this->archivePath);

        $this->info("Scanning for invoice files in: {$this->inputPath}");

        // Find all CSV files in input directory (case-insensitive)
        $files = array_merge(
            File::glob($this->inputPath.'/*.csv'),
            File::glob($this->inputPath.'/*.CSV')
        );

        if (empty($files)) {
            $this->info('No invoice files found to process.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($files).' file(s) to process.');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $filePath) {
            $filename = basename($filePath);

            // Check if already processed
            if (CarrierInvoice::isFileProcessed($filePath)) {
                $this->warn("  Skipping (already processed): {$filename}");
                $skipped++;

                continue;
            }

            // Identify carrier from filename
            $carrier = $this->identifyCarrier($filename);
            if (! $carrier) {
                $this->warn("  Skipping (unknown carrier): {$filename}");
                $skipped++;

                continue;
            }

            $this->info("  Processing: {$filename} (Carrier: {$carrier->name})");

            if ($this->option('dry-run')) {
                $this->line('    [DRY RUN] Would process and archive to: '.$this->getArchivePath($carrier, $filePath));

                continue;
            }

            try {
                // Create invoice record
                $invoice = CarrierInvoice::create([
                    'carrier_id' => $carrier->id,
                    'filename' => $filename,
                    'original_path' => $filePath,
                    'file_hash' => CarrierInvoice::computeFileHash($filePath),
                    'status' => CarrierInvoice::STATUS_PENDING,
                ]);

                // Parse the file
                $result = $parserService->parse($invoice, $filePath);

                // Archive the file
                $archivePath = $this->archiveFile($filePath, $carrier);
                $invoice->update(['archived_path' => $archivePath]);

                $this->info("    Processed: {$result['total_records']} records, {$result['corrections']} corrections, \${$result['total_charges']} charges");
                $processed++;

            } catch (\Exception $e) {
                $this->error("    Failed: {$e->getMessage()}");
                Log::error('Invoice processing failed', [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Summary: {$processed} processed, {$skipped} skipped, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Identify carrier from filename pattern.
     */
    protected function identifyCarrier(string $filename): ?Carrier
    {
        $filename = strtolower($filename);

        // UPS patterns: ups_*, UPS*, account numbers starting with certain prefixes
        if (str_contains($filename, 'ups')) {
            return Carrier::where('slug', 'ups')->first();
        }

        // FedEx patterns: fedex_*, FedEx*, etc.
        if (str_contains($filename, 'fedex') || str_contains($filename, 'fed_ex')) {
            return Carrier::where('slug', 'fedex')->first();
        }

        // Default to UPS for account number based filenames (0000*, E540W*, etc.)
        if (preg_match('/^[0-9E][0-9A-Z]{3,}/', $filename)) {
            return Carrier::where('slug', 'ups')->first();
        }

        return null;
    }

    /**
     * Get archive path for a file: Carrier/Year/Month/filename.
     */
    protected function getArchivePath(Carrier $carrier, string $filePath): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $filename = basename($filePath);

        return "{$this->archivePath}/{$carrier->slug}/{$year}/{$month}/{$filename}";
    }

    /**
     * Archive a processed file to Carrier/Year/Month structure.
     */
    protected function archiveFile(string $filePath, Carrier $carrier): string
    {
        $archivePath = $this->getArchivePath($carrier, $filePath);
        $archiveDir = dirname($archivePath);

        File::ensureDirectoryExists($archiveDir);
        File::move($filePath, $archivePath);

        return $archivePath;
    }
}
