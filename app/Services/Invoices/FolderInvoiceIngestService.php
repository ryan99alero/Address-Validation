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

        $invoices = $this->pairByInvoice(
            $this->smb->listFiles($folder, $extensions, $folder->recursive),
            $folder->prefer_csv,
        );
        $stats['found'] = count($invoices);

        $unc = '//'.$folder->smb_host.'/'.$folder->smb_share.'/';

        foreach ($invoices as $pair) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }

            $primaryTemp = $this->toTemp($pair['primary']);
            $supplementTemp = $pair['supplement'] !== null ? $this->toTemp($pair['supplement']) : null;
            $invoice = null;

            try {
                $this->smb->download($folder, $pair['primary'], $primaryTemp);
                $hash = hash_file('sha256', $primaryTemp);

                if (CarrierInvoice::where('file_hash', $hash)->exists()) {
                    $stats['skipped']++;

                    continue;
                }

                $invoice = CarrierInvoice::create([
                    'carrier_id' => $folder->carrier_id,
                    'source' => 'watch_folder',
                    'source_reference' => $unc.$pair['primary'],
                    'filename' => basename($pair['primary']),
                    'original_path' => $unc.$pair['primary'],
                    'file_hash' => $hash,
                    'status' => 'pending',
                ]);
                $this->parser->parse($invoice, $primaryTemp);

                // Fill shipments the CSV omitted from the matching PDF. Cost-safe:
                // only tracking numbers not already on the invoice are added.
                if ($supplementTemp !== null) {
                    $this->smb->download($folder, $pair['supplement'], $supplementTemp);
                    $this->parser->supplementFromPdf($invoice, $supplementTemp);
                }

                $stats['processed']++;
            } catch (Throwable $e) {
                // Drop the part-imported invoice so it retries on the next scan.
                $invoice?->delete();
                $stats['failed']++;
                $stats['errors'][] = basename($pair['primary']).': '.$e->getMessage();
            } finally {
                @unlink($primaryTemp);
                if ($supplementTemp !== null) {
                    @unlink($supplementTemp);
                }
            }
        }

        $folder->update(['last_processed_at' => now()]);

        return $stats;
    }

    /**
     * Download a remote file to a temp path that keeps its real extension, so the
     * parser routes PDF vs CSV correctly (an extensionless file parses as CSV).
     */
    private function toTemp(string $remotePath): string
    {
        $ext = strtolower(pathinfo($remotePath, PATHINFO_EXTENSION)) ?: 'dat';
        $base = (string) tempnam(sys_get_temp_dir(), 'smbinv_');
        $withExt = $base.'.'.$ext;
        @rename($base, $withExt);

        return $withExt;
    }

    /**
     * Group files into one entry per invoice, pairing the CSV (primary) with its
     * PDF (supplement). FedEx writes the CSV and PDF with different filename
     * timestamps, so pairing on the basename would import the same invoice twice —
     * we pair on the invoice date in the filename instead.
     *
     * @param  array<int, string>  $paths
     * @return array<int, array{primary: string, supplement: ?string}>
     */
    protected function pairByInvoice(array $paths, bool $preferCsv): array
    {
        $groups = [];
        foreach ($paths as $path) {
            $key = preg_match('/(\d{4}-\d{2}-\d{2})/', basename($path), $m)
                ? dirname($path).'|'.$m[1]
                : dirname($path).'|'.pathinfo($path, PATHINFO_FILENAME);
            $groups[$key][] = $path;
        }

        $invoices = [];
        foreach ($groups as $files) {
            $csvs = [];
            $pdfs = [];
            $others = [];
            foreach ($files as $p) {
                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    $csvs[] = $p;
                } elseif ($ext === 'pdf') {
                    $pdfs[] = $p;
                } else {
                    $others[] = $p;
                }
            }

            // Clean weekly invoice: one CSV (+ its single PDF) → CSV primary, PDF
            // supplement. This is the case that was double-importing.
            if ($preferCsv && count($csvs) === 1 && count($pdfs) <= 1 && $others === []) {
                $invoices[] = ['primary' => $csvs[0], 'supplement' => $pdfs[0] ?? null];

                continue;
            }

            // Anything ambiguous (no CSV, or multiple files of a type) → import each
            // on its own so nothing is lost; hash-dedup still blocks exact re-imports.
            foreach (array_merge($csvs, $pdfs, $others) as $p) {
                $invoices[] = ['primary' => $p, 'supplement' => null];
            }
        }

        return $invoices;
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
