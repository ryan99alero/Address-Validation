<?php

namespace App\Observers;

use App\Models\CarrierImportFile;
use App\Models\CarrierInvoice;

/**
 * Reporting rollups are intentionally NOT rebuilt per invoice. The full rebuild
 * (carrier rollup + shipment summary) runs for minutes and locks carrier_charges,
 * so firing it on every create/delete during a bulk import or purge starved the
 * queue workers and deadlocked against the imports themselves (lock-wait timeouts).
 *
 * Rollups now rebuild on the nightly schedule and on-demand via
 * `php artisan reports:rebuild`, which never competes with an active import.
 */
class CarrierInvoiceObserver
{
    /**
     * Source-file ids captured per invoice in deleting(), consumed in deleted() — the
     * pivot has an ON DELETE CASCADE, so by deleted() the linkage is already gone.
     *
     * @var array<int, array<int, int>>
     */
    private static array $pendingFileIds = [];

    public function created(CarrierInvoice $invoice): void
    {
        // no-op — see class docblock
    }

    /**
     * Capture the invoice's source files BEFORE the delete, while the pivot rows still
     * exist (the FK cascades them away as part of the delete).
     */
    public function deleting(CarrierInvoice $invoice): void
    {
        self::$pendingFileIds[$invoice->getKey()] = $invoice->sourceFiles()->pluck('carrier_import_files.id')->all();
    }

    /**
     * Clean up the import-file hash tracking when an invoice is deleted, so a delete →
     * re-import cycle works without leaving orphaned hashes that make the ingest skip
     * the file. A batch file can feed several invoices, so a source file is only removed
     * once its last invoice is gone.
     */
    public function deleted(CarrierInvoice $invoice): void
    {
        $fileIds = self::$pendingFileIds[$invoice->getKey()] ?? [];
        unset(self::$pendingFileIds[$invoice->getKey()]);

        if ($fileIds === []) {
            return;
        }

        CarrierImportFile::whereIn('id', $fileIds)
            ->whereDoesntHave('invoices')
            ->delete();
    }
}
