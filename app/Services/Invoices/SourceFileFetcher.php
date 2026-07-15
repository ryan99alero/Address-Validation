<?php

namespace App\Services\Invoices;

use App\Models\CarrierImportFile;
use App\Models\FolderIntegration;
use App\Models\MailIntegration;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fetches the original imported batch file to a local path for download — from the
 * SMB share it came from, a local folder path, or (mail-ingested invoices) the
 * retained archive copy. SMB files aren't stored locally (imported from a temp copy
 * that's deleted), so they're re-fetched on demand.
 */
class SourceFileFetcher
{
    public function __construct(protected SmbInvoiceReader $smb) {}

    /**
     * @return array{path: string, cleanup: bool}
     */
    public function toLocalPath(CarrierImportFile $file): array
    {
        $integration = $file->folderIntegration;

        if ($integration !== null && $integration->connection_type === FolderIntegration::TYPE_SMB) {
            $prefix = '//'.$integration->smb_host.'/'.$integration->smb_share.'/';
            $remotePath = str_starts_with((string) $file->source_reference, $prefix)
                ? substr((string) $file->source_reference, strlen($prefix))
                : ltrim((string) $file->source_reference, '/');

            $ext = strtolower(pathinfo((string) $file->filename, PATHINFO_EXTENSION)) ?: 'dat';
            $temp = (string) tempnam(sys_get_temp_dir(), 'dl_');
            $withExt = $temp.'.'.$ext;
            @rename($temp, $withExt);

            $this->smb->download($integration, $remotePath, $withExt);

            return ['path' => $withExt, 'cleanup' => true];
        }

        // Local integration: the source_reference is the on-disk path.
        if (is_file((string) $file->source_reference)) {
            return ['path' => (string) $file->source_reference, 'cleanup' => false];
        }

        // Mail-ingested invoices keep no live source_reference (the email attachment is
        // gone), but the PDF is retained at the invoice's archived_path. Serve that copy.
        $archived = $this->archivedCopy($file);
        if ($archived !== null) {
            return $archived;
        }

        throw new RuntimeException('The original file is no longer available at its source.');
    }

    /**
     * Locate the retained archive PDF for any invoice on this import file, across the
     * local-driver disks archives are written to (mail integrations' archive_disk, plus
     * the default 'local'). Returns null when nothing is on disk.
     *
     * @return array{path: string, cleanup: bool}|null
     */
    private function archivedCopy(CarrierImportFile $file): ?array
    {
        $disks = MailIntegration::query()->whereNotNull('archive_disk')->distinct()->pluck('archive_disk')->all();
        $disks = array_values(array_unique(array_merge($disks, ['local'])));

        foreach ($file->invoices()->whereNotNull('archived_path')->get() as $invoice) {
            foreach ($disks as $disk) {
                if (config("filesystems.disks.{$disk}.driver") !== 'local') {
                    continue;
                }
                if (Storage::disk($disk)->exists($invoice->archived_path)) {
                    return ['path' => Storage::disk($disk)->path($invoice->archived_path), 'cleanup' => false];
                }
            }
        }

        return null;
    }
}
