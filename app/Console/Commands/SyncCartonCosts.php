<?php

namespace App\Console\Commands;

use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Console\Command;

/**
 * Pulls Pace carton ship costs for tracking numbers that have carrier charges but no carton
 * mirror yet, so customer recoup (invoiced total − ship cost) has its baseline. Populates the
 * carton_costs table from the active Pace integration.
 */
class SyncCartonCosts extends Command
{
    protected $signature = 'recoup:sync-cartons';

    protected $description = 'Pull Pace carton ship costs (by tracking number) into the recoup baseline mirror';

    public function handle(CartonCostSyncService $sync): int
    {
        $pending = count($sync->pendingTrackingNumbers());
        if ($pending === 0) {
            $this->info('No pending tracking numbers — carton mirror is up to date.');

            return self::SUCCESS;
        }

        $this->info("Syncing carton costs for {$pending} pending tracking number(s) from Pace…");

        $written = $sync->syncFromPace();

        if ($written === null) {
            $this->warn('No active Pace integration is configured — nothing pulled.');

            return self::FAILURE;
        }

        $this->info("Done. {$written} carton cost(s) written to the recoup mirror.");

        return self::SUCCESS;
    }
}
