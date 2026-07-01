<?php

namespace App\Services\Invoices;

use App\Models\CarrierImportFile;
use App\Models\FolderIntegration;
use RuntimeException;

/**
 * Fetches the original imported batch file to a local path for download — from the
 * SMB share it came from, or a local folder path. SMB files aren't stored locally
 * (imported from a temp copy that's deleted), so they're re-fetched on demand.
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

        throw new RuntimeException('The original file is no longer available at its source.');
    }
}
