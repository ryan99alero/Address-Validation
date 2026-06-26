<?php

namespace App\Jobs;

use App\Services\CarrierRollupService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds the carrier reporting rollups off the queue. ShouldBeUnique so a burst
 * of invoice imports/deletions coalesces into a single rebuild rather than one
 * per record.
 */
class RebuildCarrierRollup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'carrier-rollup';
    }

    public function handle(CarrierRollupService $service): void
    {
        $service->rebuild();
    }
}
