<?php

namespace App\Console\Commands;

use App\Models\CarrierImportFile;
use App\Models\FolderIntegration;
use App\Services\CarrierInvoiceParserService;
use App\Services\Invoices\SmbInvoiceReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backfills FedEx shipments (destination ZIP + ship method) into carrier_shipments by re-importing
 * the original FedEx invoice files from their source (SMB share or local folder). The destination
 * ZIP isn't in carrier_charges, so it can only come from re-parsing the raw file. Calls the parser
 * directly (bypassing the content-hash dedup the normal ingest uses) — the re-import is idempotent:
 * charges dedup by multiset, shipments delete-then-insert. Best-effort: files whose source no longer
 * resolves are skipped and reported.
 */
class BackfillFedExShipments extends Command
{
    protected $signature = 'fedex:backfill-shipments
        {--limit=0 : Max source files to re-import (0 = all)}
        {--dry-run : Resolve + count files without re-importing}';

    protected $description = 'Re-import FedEx invoice files from their SMB/local source to backfill carrier_shipments (ZIP + service)';

    public function handle(CarrierInvoiceParserService $parser, SmbInvoiceReader $smb): int
    {
        $fedexId = DB::table('carriers')->where('slug', 'fedex')->value('id');
        if ($fedexId === null) {
            $this->error('No FedEx carrier found.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $files = CarrierImportFile::query()
            ->where('carrier_id', $fedexId)
            ->whereNotNull('folder_integration_id')
            ->whereNotNull('source_reference')->where('source_reference', '<>', '')
            ->with('folderIntegration')
            ->orderByRaw("(filename LIKE '%.csv') DESC") // CSV first — it's what captures shipments
            ->orderBy('id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get();

        $this->info("Re-importing {$files->count()} FedEx source file(s)…");
        $ok = $skip = $fail = 0;

        foreach ($files as $file) {
            $folder = $file->folderIntegration;
            if ($folder === null) {
                $skip++;

                continue;
            }

            $temp = null;
            try {
                $local = $this->resolveLocalCopy($folder, $file, $smb, $temp);
                if ($local === null) {
                    $skip++;
                    $this->line("  <fg=yellow>skip</> {$file->filename} — source not reachable");

                    continue;
                }

                if ($dryRun) {
                    $ok++;

                    continue;
                }

                $parser->importFile($fedexId, $local, $file->filename);
                $ok++;
            } catch (Throwable $e) {
                $fail++;
                $this->line("  <fg=red>fail</> {$file->filename} — ".$e->getMessage());
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

        $verb = $dryRun ? 'reachable' : 're-imported';
        $shipments = DB::table('carrier_shipments')->where('carrier_id', $fedexId)->count();
        $this->info("{$verb}: {$ok}, skipped (unreachable): {$skip}, failed: {$fail}. FedEx carrier_shipments now: {$shipments}.");

        return self::SUCCESS;
    }

    /**
     * Get a readable local path for the file — download from SMB to a temp path (extension
     * preserved so the parser routes CSV vs PDF), or use the local absolute path directly. Returns
     * null when the source can't be reached; sets $temp when a temp file was created.
     */
    private function resolveLocalCopy(FolderIntegration $folder, CarrierImportFile $file, SmbInvoiceReader $smb, ?string &$temp): ?string
    {
        if ($folder->connection_type === FolderIntegration::TYPE_SMB) {
            $unc = '//'.$folder->smb_host.'/'.$folder->smb_share.'/';
            if (! str_starts_with((string) $file->source_reference, $unc)) {
                return null;
            }
            $remote = substr((string) $file->source_reference, strlen($unc));
            $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION)) ?: 'dat';
            $temp = tempnam(sys_get_temp_dir(), 'fxbf_').'.'.$ext;
            $smb->download($folder, $remote, $temp);

            return is_readable($temp) && filesize($temp) > 0 ? $temp : null;
        }

        $path = (string) $file->source_reference;

        return is_readable($path) ? $path : null;
    }
}
