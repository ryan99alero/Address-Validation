<?php

namespace App\Console\Commands;

use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Console\Command;

/**
 * Backfill the era-correct carton link (carrier_charges.carton_cost_id) for recent-invoice charges,
 * repairing the historic tracking-only attribution where a recycled 1Z married an old charge to a
 * newer job. Idempotent — safe to re-run and to schedule as a sweep.
 */
class StampChargeCartons extends Command
{
    protected $signature = 'carton:stamp-charges';

    protected $description = 'Stamp carrier_charges.carton_cost_id for recent-invoice charges (era-correct carton link)';

    public function handle(CartonCostSyncService $sync): int
    {
        $this->info('Stamping carton_cost_id on recent-invoice charges…');
        $stamped = $sync->stampRecentCharges();
        $this->info("Done — {$stamped} charge(s) linked to their carton.");

        return self::SUCCESS;
    }
}
