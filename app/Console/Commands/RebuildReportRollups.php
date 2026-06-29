<?php

namespace App\Console\Commands;

use App\Services\CarrierRollupService;
use App\Services\ShipmentSummaryService;
use Illuminate\Console\Command;

class RebuildReportRollups extends Command
{
    protected $signature = 'reports:rebuild';

    protected $description = 'Rebuild the carrier reporting rollups + per-shipment summary from carrier_charges';

    public function handle(CarrierRollupService $rollups, ShipmentSummaryService $shipments): int
    {
        $start = microtime(true);
        $rollups->rebuild();
        $this->info(sprintf('Carrier rollups rebuilt in %.1fs.', microtime(true) - $start));

        $start = microtime(true);
        $shipments->rebuild();
        $this->info(sprintf('Per-shipment summary rebuilt in %.1fs.', microtime(true) - $start));

        return self::SUCCESS;
    }
}
