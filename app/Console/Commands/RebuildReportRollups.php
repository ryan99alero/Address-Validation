<?php

namespace App\Console\Commands;

use App\Services\CarrierRollupService;
use Illuminate\Console\Command;

class RebuildReportRollups extends Command
{
    protected $signature = 'reports:rebuild';

    protected $description = 'Rebuild the carrier reporting rollups from carrier_charges';

    public function handle(CarrierRollupService $rollups): int
    {
        $start = microtime(true);
        $rollups->rebuild();
        $this->info(sprintf('Carrier rollups rebuilt in %.1fs.', microtime(true) - $start));

        return self::SUCCESS;
    }
}
