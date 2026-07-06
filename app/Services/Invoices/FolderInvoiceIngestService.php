<?php

namespace App\Services\Invoices;

use App\Models\CarrierImportFile;
use App\Models\FolderIntegration;
use App\Services\CarrierInvoiceParserService;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class FolderInvoiceIngestService
{
    /**
     * Hard per-file import ceiling (seconds). A single oversized batch PDF (some FedEx files
     * hold 500-800 invoices / ~16k charges and take minutes of per-row DB work) must not pin a
     * worker past the chunk timeout. Interruptible via SIGALRM; the file is then logged as
     * failed and re-attempted on the next scan (content-hash dedup skips whatever imported).
     */
    private const FILE_IMPORT_TIMEOUT = 900;

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
        $files = $this->listCandidates($folder, $scanPath);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        return $this->ingestFiles($folder, $files);
    }

    /**
     * Enumerate the candidate files to import, CSV-first, as opaque identifiers the
     * matching ingestFiles() understands (remote paths for SMB, absolute paths for
     * local). Done once so a chunked dispatch doesn't re-list per job.
     *
     * @return array<int, string>
     */
    public function listCandidates(FolderIntegration $folder, ?string $scanPath = null): array
    {
        $extensions = $folder->extensions() ?: ['csv', 'pdf'];

        if ($folder->connection_type === FolderIntegration::TYPE_SMB) {
            return $this->orderCsvFirst($this->smb->listFiles($folder, $extensions, $folder->recursive));
        }

        // A specific sub-path (e.g. a single year) keeps each run small & fast.
        $path = $scanPath !== null ? rtrim($scanPath, '/') : $this->resolvePath($folder);
        if (! is_dir($path)) {
            throw new RuntimeException("Folder not found or not accessible: {$path}");
        }

        return $this->collectAllFiles($path, $extensions, $folder->recursive);
    }

    /**
     * Import a specific list of files (a chunk). Each file is content-hash deduped, so
     * a retried chunk only redoes the files it hadn't finished. Order across chunks
     * doesn't affect correctness — charges carry source_type for read-time precedence.
     *
     * @param  array<int, string>  $files
     * @return array{found: int, processed: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function ingestFiles(FolderIntegration $folder, array $files): array
    {
        $stats = ['found' => count($files), 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $isSmb = $folder->connection_type === FolderIntegration::TYPE_SMB;
        $unc = $isSmb ? '//'.$folder->smb_host.'/'.$folder->smb_share.'/' : '';

        foreach ($files as $file) {
            $temp = null;
            try {
                if ($isSmb) {
                    $temp = $this->toTemp($file);
                    $this->smb->download($folder, $file, $temp);
                    $localPath = $temp;
                    $reference = $unc.$file;
                } else {
                    $localPath = $file;
                    $reference = $file;
                }

                $hash = hash_file('sha256', $localPath);
                if ($this->alreadyImported($hash)) {
                    $stats['skipped']++;

                    continue;
                }

                // Log which file we're about to import, so a chunk that dies mid-file names the
                // culprit (previously chunks only logged on completion — hangs were invisible).
                Log::info('Ingest: importing file', ['integration_id' => $folder->id, 'file' => basename($file)]);

                $ids = $this->importFileGuarded($folder->carrier_id, $localPath, basename($file));
                $this->recordImport($folder, $hash, basename($file), $reference, $ids, $this->parser->lastSkipReason);
                $stats['processed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = basename($file).': '.$e->getMessage();
                // A per-file timeout can interrupt an in-flight query; give the next file a
                // healthy connection.
                try {
                    DB::reconnect();
                } catch (Throwable) {
                }
            } finally {
                if ($temp !== null) {
                    @unlink($temp);
                }
            }
        }

        $folder->update(['last_processed_at' => now()]);

        return $stats;
    }

    /**
     * Import one file under a hard SIGALRM deadline so a pathological/oversized file can't hang
     * a worker. Falls back to a plain import where pcntl is unavailable (the chunk timeout is
     * then the only backstop).
     *
     * @return array<int, int>
     */
    protected function importFileGuarded(int $carrierId, string $path, string $name): array
    {
        if (self::FILE_IMPORT_TIMEOUT <= 0 || ! function_exists('pcntl_async_signals')) {
            return $this->parser->importFile($carrierId, $path, $name);
        }

        pcntl_async_signals(true);
        $previous = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, function () use ($name): void {
            throw new RuntimeException(sprintf('Import exceeded %ds (oversized batch file): %s', self::FILE_IMPORT_TIMEOUT, $name));
        });
        pcntl_alarm(self::FILE_IMPORT_TIMEOUT);

        try {
            return $this->parser->importFile($carrierId, $path, $name);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, is_callable($previous) || is_int($previous) ? $previous : SIG_DFL);
        }
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

    protected function alreadyImported(string $hash): bool
    {
        return CarrierImportFile::where('file_hash', $hash)->exists();
    }

    /**
     * @param  array<int, int>  $invoiceIds
     */
    protected function recordImport(FolderIntegration $folder, string $hash, string $filename, string $reference, array $invoiceIds, ?string $skipReason = null): void
    {
        $file = CarrierImportFile::create([
            'carrier_id' => $folder->carrier_id,
            'folder_integration_id' => $folder->id,
            'file_hash' => $hash,
            'filename' => $filename,
            'source_reference' => $reference,
            'invoice_count' => count($invoiceIds),
            'skip_reason' => $skipReason,
            'imported_at' => now(),
        ]);

        if ($invoiceIds !== []) {
            $file->invoices()->syncWithoutDetaching($invoiceIds);
        }
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
