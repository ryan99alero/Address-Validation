<?php

namespace App\Console\Commands;

use App\Services\CarrierRollupService;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Console\Command;

class RebuildReportRollups extends Command
{
    protected $signature = 'reports:rebuild';

    protected $description = 'Rebuild the carrier reporting rollups from carrier_charges';

    public function handle(CarrierRollupService $rollups, CartonCostSyncService $cartons): int
    {
        $start = microtime(true);

        // Keep the era-correct carton link fresh (re-imports recreate charges unstamped) before the
        // rollups read it.
        $stamped = $cartons->stampRecentCharges();
        $this->info("Stamped carton_cost_id on {$stamped} recent charge(s).");

        $rollups->rebuild();
        $this->info(sprintf('Carrier rollups rebuilt in %.1fs.', microtime(true) - $start));

        return self::SUCCESS;
    }
}
