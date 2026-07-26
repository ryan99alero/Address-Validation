<?php

namespace App\Console\Commands;

use App\Jobs\RecategorizeChargesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs category resolution over existing charges after a crosswalk/mapping/resolver change.
 * Resolution depends only on (carrier_id, source_type, raw_charge_code, raw_charge_description), so
 * each DISTINCT combo is resolved once and all its rows bulk-updated — fast and idempotent.
 * Optional --carrier=ups|fedex and repeatable --description= limit the scope.
 */
class ReresolveChargeCategories extends Command
{
    protected $signature = 'charges:reresolve-categories
        {--carrier= : Limit to a carrier slug}
        {--description=* : Limit to specific raw charge descriptions}';

    protected $description = 'Recompute charge_category_id + charge_type_id for existing charges from the current crosswalk/mappings';

    public function handle(): int
    {
        $carrierId = null;
        if ($slug = $this->option('carrier')) {
            $carrierId = DB::table('carriers')->where('slug', $slug)->value('id');
            if ($carrierId === null) {
                $this->error("Unknown carrier slug: {$slug}");

                return self::FAILURE;
            }
        }

        /** @var list<string> $descriptions */
        $descriptions = $this->option('description') ?: [];

        $changed = RecategorizeChargesJob::run(
            $carrierId,
            $descriptions,
            fn (int $count) => $this->info("Resolving {$count} distinct charge combos…"),
        );

        $this->info("Re-resolve complete. Rows updated: {$changed}.");

        return self::SUCCESS;
    }
}
