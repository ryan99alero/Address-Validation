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
    ) {}

    /**
     * Ingest invoice files from a folder integration.
     *
     * @return array{found: int, processed: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function ingest(FolderIntegration $folder, int $limit = 0, ?string $scanPath = null): array
    {
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
     * Resolve the absolute filesystem path to scan.
     */
    public function resolvePath(FolderIntegration $folder): string
    {
        if ($folder->connection_type === FolderIntegration::TYPE_SMB) {
            throw new RuntimeException(
                'SMB direct connection is not yet available. Mount the share on the server and use a Local (mounted) path integration.'
            );
        }

        $path = rtrim($folder->base_path, '/');
        if (! is_dir($path)) {
            throw new RuntimeException("Folder not found or not accessible: {$path}");
        }

        return $path;
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
