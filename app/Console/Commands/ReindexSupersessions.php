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
    protected $signature = 'correction-cache:reindex-supersessions {--only-empty : Only events missing search_text}';

    protected $description = 'Rebuild the searchable text index on re-correction events';

    public function handle(): int
    {
        $query = AddressSupersession::query()
            ->when($this->option('only-empty'), fn ($q) => $q->whereNull('search_text'));

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
