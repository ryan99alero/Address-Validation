<?php

namespace App\Console\Commands;

use App\Services\Invoices\ChargeCategoryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs category resolution over existing charges after a mapping/resolver change (e.g. the
 * driver-prefix strip). Category resolution depends only on (carrier_id, raw_charge_code,
 * raw_charge_description), so we resolve each DISTINCT combo once and update all its rows in bulk —
 * fast and idempotent. Optional --carrier=ups|fedex limits the scope.
 */
class ReresolveChargeCategories extends Command
{
    protected $signature = 'charges:reresolve-categories {--carrier= : Limit to a carrier slug}';

    protected $description = 'Recompute charge_category_id for existing charges from the current mappings';

    public function handle(): int
    {
        $resolver = new ChargeCategoryResolver;

        $carrierId = null;
        if ($slug = $this->option('carrier')) {
            $carrierId = DB::table('carriers')->where('slug', $slug)->value('id');
            if ($carrierId === null) {
                $this->error("Unknown carrier slug: {$slug}");

                return self::FAILURE;
            }
        }

        $combos = DB::table('carrier_charges')
            ->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))
            ->select('carrier_id', 'raw_charge_code', 'raw_charge_description')
            ->distinct()
            ->get();

        $this->info("Resolving {$combos->count()} distinct charge combos…");
        $changed = 0;

        foreach ($combos as $c) {
            $categoryId = $resolver->resolve($c->carrier_id, $c->raw_charge_code, $c->raw_charge_description);

            // Setting a row to the value it already holds is a no-op that MySQL excludes from the
            // affected-row count, so the total reflects genuine re-categorizations.
            $changed += DB::table('carrier_charges')
                ->where('carrier_id', $c->carrier_id)
                ->where(fn ($w) => $c->raw_charge_code === null ? $w->whereNull('raw_charge_code') : $w->where('raw_charge_code', $c->raw_charge_code))
                ->where(fn ($w) => $c->raw_charge_description === null ? $w->whereNull('raw_charge_description') : $w->where('raw_charge_description', $c->raw_charge_description))
                ->update(['charge_category_id' => $categoryId]);
        }

        $this->info("Re-resolve complete. Rows updated: {$changed}.");

        return self::SUCCESS;
    }
}
