<?php

namespace App\Observers;

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
    public function created(CarrierInvoice $invoice): void
    {
        // no-op — see class docblock
    }

    public function deleted(CarrierInvoice $invoice): void
    {
        // no-op — see class docblock
    }
}
