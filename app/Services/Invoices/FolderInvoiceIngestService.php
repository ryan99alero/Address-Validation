<?php

namespace App\Services\Invoices;

use App\Models\CarrierImportFile;
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
     * Ingest invoice files from a folder integration. Each file is a batch that the
     * importer splits into real invoices (one CarrierInvoice per invoice number);
     * files are deduped by content hash, and CSVs are processed before PDFs so the
     * cleaner source wins on shared charges.
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

        $files = $this->collectAllFiles($path, $extensions, $folder->recursive);
        $stats['found'] = count($files);

        foreach ($files as $filePath) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }

            $hash = hash_file('sha256', $filePath);
            if ($this->alreadyImported($hash)) {
                $stats['skipped']++;

                continue;
            }

            try {
                $ids = $this->parser->importFile($folder->carrier_id, $filePath);
                $this->recordImport($folder, $hash, basename($filePath), $filePath, count($ids));
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
     * Ingest from a direct SMB share: list remote files, stream each to a temp file,
     * dedup by content hash, and split each batch into real invoices.
     *
     * @return array{found: int, processed: int, skipped: int, failed: int, errors: array<int, string>}
     */
    protected function ingestSmb(FolderIntegration $folder, int $limit): array
    {
        $stats = ['found' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $extensions = $folder->extensions() ?: ['csv', 'pdf'];

        $files = $this->orderCsvFirst($this->smb->listFiles($folder, $extensions, $folder->recursive));
        $stats['found'] = count($files);

        $unc = '//'.$folder->smb_host.'/'.$folder->smb_share.'/';

        foreach ($files as $remotePath) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }

            $temp = $this->toTemp($remotePath);

            try {
                $this->smb->download($folder, $remotePath, $temp);
                $hash = hash_file('sha256', $temp);

                if ($this->alreadyImported($hash)) {
                    $stats['skipped']++;

                    continue;
                }

                $ids = $this->parser->importFile($folder->carrier_id, $temp);
                $this->recordImport($folder, $hash, basename($remotePath), $unc.$remotePath, count($ids));
                $stats['processed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = basename($remotePath).': '.$e->getMessage();
            } finally {
                @unlink($temp);
            }
        }

        $folder->update(['last_processed_at' => now()]);

        return $stats;
    }

    protected function alreadyImported(string $hash): bool
    {
        return CarrierImportFile::where('file_hash', $hash)->exists();
    }

    protected function recordImport(FolderIntegration $folder, string $hash, string $filename, string $reference, int $invoiceCount): void
    {
        CarrierImportFile::create([
            'carrier_id' => $folder->carrier_id,
            'file_hash' => $hash,
            'filename' => $filename,
            'source_reference' => $reference,
            'invoice_count' => $invoiceCount,
            'imported_at' => now(),
        ]);
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
     * All matching files under a path (batch files are split by the importer, so no
     * CSV/PDF collapsing here), CSV-first.
     *
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    protected function collectAllFiles(string $path, array $extensions, bool $recursive): array
    {
        $files = [];
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
            : new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $this->orderCsvFirst($files);
    }

    /**
     * Process CSVs before PDFs so the cleaner CSV descriptions win on shared charges
     * (the PDF only supplements what the CSV lacks).
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function orderCsvFirst(array $paths): array
    {
        usort($paths, function (string $a, string $b): int {
            $pa = strtolower(pathinfo($a, PATHINFO_EXTENSION)) === 'csv' ? 0 : 1;
            $pb = strtolower(pathinfo($b, PATHINFO_EXTENSION)) === 'csv' ? 0 : 1;

            return $pa <=> $pb ?: strcmp($a, $b);
        });

        return $paths;
    }
}
