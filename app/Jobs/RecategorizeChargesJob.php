<?php

namespace App\Jobs;

use App\Services\Invoices\ChargeCategoryResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs category resolution over existing charges after a crosswalk/mapping change. Category
 * resolution depends only on (carrier_id, source_type, raw_charge_code, raw_charge_description), so
 * we resolve each DISTINCT combo once and bulk-update all its rows — O(distinct combos), not
 * O(rows). Optionally scoped to a carrier and/or a set of descriptions (a single crosswalk edit).
 */
class RecategorizeChargesJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $descriptions
     */
    public function __construct(
        public ?int $carrierId = null,
        public array $descriptions = [],
    ) {}

    public function handle(): void
    {
        $changed = self::run($this->carrierId, $this->descriptions);

        Log::info('RecategorizeChargesJob complete', [
            'carrier_id' => $this->carrierId,
            'descriptions' => $this->descriptions,
            'rows_updated' => $changed,
        ]);
    }

    /**
     * Resolve each distinct charge combo once and bulk-update its rows. Returns the number of rows
     * whose category or charge-type changed. $onProgress receives the distinct-combo count once.
     *
     * @param  list<string>  $descriptions
     */
    public static function run(?int $carrierId, array $descriptions = [], ?callable $onProgress = null): int
    {
        $resolver = new ChargeCategoryResolver;

        $combos = DB::table('carrier_charges')
            ->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))
            ->when($descriptions !== [], fn ($q) => $q->whereIn('raw_charge_description', $descriptions))
            ->select('carrier_id', 'source_type', 'raw_charge_code', 'raw_charge_description')
            ->distinct()
            ->get();

        if ($onProgress !== null) {
            $onProgress($combos->count());
        }

        $changed = 0;
        foreach ($combos as $c) {
            [$categoryId, $chargeTypeId] = $resolver->resolveDetailed(
                $c->carrier_id, $c->raw_charge_code, $c->raw_charge_description, $c->source_type
            );

            // Setting a row to the value it already holds is a no-op MySQL excludes from the
            // affected-row count, so the total reflects genuine re-categorizations.
            $changed += DB::table('carrier_charges')
                ->where('carrier_id', $c->carrier_id)
                ->where(fn ($w) => $c->source_type === null ? $w->whereNull('source_type') : $w->where('source_type', $c->source_type))
                ->where(fn ($w) => $c->raw_charge_code === null ? $w->whereNull('raw_charge_code') : $w->where('raw_charge_code', $c->raw_charge_code))
                ->where(fn ($w) => $c->raw_charge_description === null ? $w->whereNull('raw_charge_description') : $w->where('raw_charge_description', $c->raw_charge_description))
                ->update(['charge_category_id' => $categoryId, 'charge_type_id' => $chargeTypeId]);
        }

        return $changed;
    }
}
