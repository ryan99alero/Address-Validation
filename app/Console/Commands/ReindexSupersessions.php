<?php

namespace App\Console\Commands;

use App\Models\AddressSupersession;
use Illuminate\Console\Command;

/**
 * (Re)builds the denormalized search_text on every re-correction event so the Re-Corrections search
 * box can match by tracking, invoice number, Pace job/customer, or either correction's addresses.
 * Run once after backfill; new events index themselves on creation.
 */
class ReindexSupersessions extends Command
{
    protected $signature = 'correction-cache:reindex-supersessions {--only-empty : Only events missing search_text} {--missing-info : Only events missing Job/Customer that a now-synced carton (or the chargeback ledger) can fill}';

    protected $description = 'Rebuild the searchable text index on re-correction events';

    public function handle(): int
    {
        $query = AddressSupersession::query()
            ->when($this->option('only-empty'), fn ($q) => $q->whereNull('search_text'))
            // The nightly self-healing sweep: an event indexed BEFORE its invoice's carton synced from
            // Pace froze a null Job/Customer (a timing race). Re-stamp only those now resolvable by a
            // synced carton or the ledger — so it never churns events that genuinely have no carton.
            ->when($this->option('missing-info'), fn ($q) => $q
                ->whereNull('pace_job')
                ->whereNotNull('tracking')
                ->where('tracking', '<>', '')
                ->where(fn ($w) => $w
                    ->whereExists(fn ($e) => $e->from('carton_costs')
                        ->whereColumn('carton_costs.tracking_number', 'address_supersessions.tracking')
                        ->whereNotNull('carton_costs.pace_job_number'))
                    ->orWhereExists(fn ($e) => $e->from('chargeback_pushes')
                        ->whereColumn('chargeback_pushes.tracking_number', 'address_supersessions.tracking')
                        ->whereNotNull('chargeback_pushes.pace_job'))));

        $total = $query->count();
        $this->info("Reindexing {$total} re-correction event(s)...");

        $done = 0;
        $query->chunkById(200, function ($events) use (&$done, $total): void {
            foreach ($events as $event) {
                $event->rebuildSearchText();
                $done++;
            }
            $this->line("  {$done}/{$total}");
        });

        $this->info("Reindexed {$done} event(s).");

        return self::SUCCESS;
    }
}
