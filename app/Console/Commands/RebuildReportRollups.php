<?php

namespace App\Console\Commands;

use App\Services\CarrierRollupService;
use Illuminate\Console\Command;

class RebuildReportRollups extends Command
{
    protected $signature = 'reports:rebuild';

    protected $description = 'Rebuild the carrier reporting rollups from the raw carrier_charges table';

    public function handle(CarrierRollupService $service): int
    {
        $start = microtime(true);
        $service->rebuild();
        $this->info(sprintf('Carrier rollups rebuilt in %.1fs.', microtime(true) - $start));

        return self::SUCCESS;
    }
}
