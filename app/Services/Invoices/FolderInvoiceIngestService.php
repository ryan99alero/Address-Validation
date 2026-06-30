<?php

namespace App\Services\Invoices;

use App\Models\CarrierInvoice;
use App\Models\FolderIntegration;
use App\Services\CarrierInvoiceParserService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class FolderInvoiceIngestService
{
    public function __construct(
        protected CarrierInvoiceParserService $parser,
        protected SmbInvoiceReader $smb,
    ) {}

    /**
     * Ingest invoice files from a folder integration.
     *
     * @return array{found: int, processed: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function ingest(FolderIntegration $folder, int $limit = 0, ?string $scanPath = null): array
    {
        if ($folder->connection_type === FolderIntegration::TYPE_SMB) {
            return $this->ingestSmb($folder, $limit);
        }

        $stats = ['found' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        // A specific sub-path (e.g. a single year) keeps each run small & fast.
        $path = $scanPath !== null ? rtrim($scanPath, '/') : $this->resolvePath($folder);
        if (! is_dir($path)) {
            throw new RuntimeException("Folder not found or not accessible: {$path}");
        }
        $extensions = $folder->extensions() ?: ['csv', 'pdf'];

        $files = $this->collectFiles($path, $extensions, $folder->recursive, $folder->prefer_csv);
        $stats['found'] = count($files);

        foreach ($files as $filePath) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }

            $hash = hash_file('sha256', $filePath);
            if (CarrierInvoice::where('file_hash', $hash)->exists()) {
                $stats['skipped']++;

                continue;
            }

            try {
                $invoice = CarrierInvoice::create([
                    'carrier_id' => $folder->carrier_id,
                    'source' => 'watch_folder',
                    'source_reference' => $filePath,
                    'filename' => basename($filePath),
                    'original_path' => $filePath,
                    'file_hash' => $hash,
                    'status' => 'pending',
                ]);
                $this->parser->parse($invoice, $filePath);
                $stats['processed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = basename($filePath).': '.$e->getMessage();
            }
        }

        $folder->update(['last_processed_at' => now()]);

        return $stats;
    }

    /**
     * Resolve the absolute filesystem path to scan (Local connections).
     */
    public function resolvePath(FolderIntegration $folder): string
    {
        $path = rtrim($folder->base_path, '/');
        if (! is_dir($path)) {
            throw new RuntimeException("Folder not found or not accessible: {$path}");
        }

        return $path;
    }

    /**
     * Ingest from a direct SMB share: list remote files, stream each to a temp
     * file, and run it through the same hash/dedup/parse pipeline.
     *
     * @return array{found: int, processed: int, skipped: int, failed: int, errors: array<int, string>}
     */
    protected function ingestSmb(FolderIntegration $folder, int $limit): array
    {
        $stats = ['found' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $extensions = $folder->extensions() ?: ['csv', 'pdf'];

        $files = $this->dedupePreferCsv(
            $this->smb->listFiles($folder, $extensions, $folder->recursive),
            $folder->prefer_csv,
        );
        $stats['found'] = count($files);

        $unc = '//'.$folder->smb_host.'/'.$folder->smb_share.'/';

        foreach ($files as $remotePath) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }

            // Preserve the real extension so the parser routes PDF vs CSV correctly —
            // an extensionless temp file makes every download parse as CSV.
            $ext = strtolower(pathinfo($remotePath, PATHINFO_EXTENSION)) ?: 'dat';
            $temp = (string) tempnam(sys_get_temp_dir(), 'smbinv_');
            $withExt = $temp.'.'.$ext;
            @rename($temp, $withExt);
            $temp = $withExt;

            $invoice = null;

            try {
                $this->smb->download($folder, $remotePath, $temp);
                $hash = hash_file('sha256', $temp);

                if (CarrierInvoice::where('file_hash', $hash)->exists()) {
                    $stats['skipped']++;

                    continue;
                }

                $invoice = CarrierInvoice::create([
                    'carrier_id' => $folder->carrier_id,
                    'source' => 'watch_folder',
                    'source_reference' => $unc.$remotePath,
                    'filename' => basename($remotePath),
                    'original_path' => $unc.$remotePath,
                    'file_hash' => $hash,
                    'status' => 'pending',
                ]);
                $this->parser->parse($invoice, $temp);
                $stats['processed']++;
            } catch (Throwable $e) {
                // Drop the part-imported invoice so it retries on the next scan.
                $invoice?->delete();
                $stats['failed']++;
                $stats['errors'][] = basename($remotePath).': '.$e->getMessage();
            } finally {
                @unlink($temp);
            }
        }

        $folder->update(['last_processed_at' => now()]);

        return $stats;
    }

    /**
     * Keep one file per invoice (same folder + basename), preferring CSV.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function dedupePreferCsv(array $paths, bool $preferCsv): array
    {
        $byInvoice = [];

        foreach ($paths as $path) {
            $key = dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME);
            $existing = $byInvoice[$key] ?? null;

            if ($existing === null
                || ($preferCsv && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv' && strtolower(pathinfo($existing, PATHINFO_EXTENSION)) !== 'csv')) {
                $byInvoice[$key] = $path;
            }
        }

        return array_values($byInvoice);
    }

    /**
     * Collect candidate invoice files, preferring CSV over PDF for the same invoice.
     *
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    protected function collectFiles(string $path, array $extensions, bool $recursive, bool $preferCsv): array
    {
        $byInvoice = [];
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
            : new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, $extensions, true)) {
                continue;
            }

            $key = $file->getPath().'/'.$file->getBasename('.'.$file->getExtension());
            $existing = $byInvoice[$key] ?? null;
            if ($existing === null
                || ($preferCsv && $ext === 'csv' && strtolower(pathinfo($existing, PATHINFO_EXTENSION)) !== 'csv')) {
                $byInvoice[$key] = $file->getPathname();
            }
        }

        $files = array_values($byInvoice);
        sort($files);

        return $files;
    }
}
