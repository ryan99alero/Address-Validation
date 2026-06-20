<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;
use Throwable;

class ScanInvoiceFolder extends Command
{
    protected $signature = 'invoices:scan-folder
        {path : Base folder to scan recursively}
        {carrier : Carrier slug (ups|fedex)}
        {--limit=0 : Max invoices to process (0 = all)}
        {--csv-only : Only process .csv files}';

    protected $description = 'Ingest carrier invoice files from a (mounted) server folder';

    public function handle(CarrierInvoiceParserService $parser): int
    {
        // Keep memory flat on big folders (thousands of inserts).
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        $path = rtrim($this->argument('path'), '/');
        $carrier = Carrier::where('slug', $this->argument('carrier'))->first();
        if (! $carrier) {
            $this->error("Unknown carrier: {$this->argument('carrier')}");

            return self::FAILURE;
        }
        if (! is_dir($path)) {
            $this->error("Folder not found: {$path}");

            return self::FAILURE;
        }

        $exts = $this->option('csv-only') ? ['csv'] : ['csv', 'pdf'];
        $limit = (int) $this->option('limit');

        // Prefer CSV over PDF when both exist for the same invoice (same basename).
        $byInvoice = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, $exts, true)) {
                continue;
            }
            $key = $file->getPath().'/'.$file->getBasename('.'.$file->getExtension());
            // CSV wins over PDF for the same invoice.
            if (! isset($byInvoice[$key]) || ($ext === 'csv' && strtolower(pathinfo($byInvoice[$key], PATHINFO_EXTENSION)) !== 'csv')) {
                $byInvoice[$key] = $file->getPathname();
            }
        }

        $files = array_values($byInvoice);
        sort($files);
        $this->info('Found '.count($files).' invoice file(s) to consider.');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $filePath) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $hash = hash_file('sha256', $filePath);
            if (CarrierInvoice::where('file_hash', $hash)->exists()) {
                $skipped++;

                continue;
            }

            try {
                $invoice = CarrierInvoice::create([
                    'carrier_id' => $carrier->id,
                    'source' => 'watch_folder',
                    'source_reference' => $filePath,
                    'filename' => basename($filePath),
                    'original_path' => $filePath,
                    'file_hash' => $hash,
                    'status' => 'pending',
                ]);
                $parser->parse($invoice, $filePath);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn('  '.basename($filePath).': '.$e->getMessage());
            }

            if (($processed + $skipped) % 100 === 0) {
                $this->line("  ...{$processed} processed, {$skipped} skipped");
            }
        }

        $this->info("Done. Processed {$processed}, skipped {$skipped} (already imported), failed {$failed}.");

        return self::SUCCESS;
    }
}
