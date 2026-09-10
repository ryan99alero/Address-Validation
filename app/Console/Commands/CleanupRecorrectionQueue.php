<?php

namespace App\Console\Commands;

use App\Models\AddressSupersession;
use App\Services\Invoices\RecorrectionRules;
use Illuminate\Console\Command;

/**
 * One-time (re-runnable) cleanup of the Re-Corrections queue:
 *  1) Dismiss pending reverify-drift events that are fee-free reformats (carrier dropped a trailing
 *     suite/unit, same city/state/ZIP) — the same rule the reverify job now applies going forward.
 *  2) Repair implausible reference dates (old UPS 2-digit years that parsed to year 00xx, e.g.
 *     "0020-11-07" -> "2020-11-07"; anything still implausible becomes null instead of a fake date).
 *
 * Idempotent and safe to re-run. Actual invoice correction fees live on the recorrection path and are
 * never touched here.
 */
class CleanupRecorrectionQueue extends Command
{
    protected $signature = 'recorrections:cleanup {--dry-run : Report what would change without writing}';

    protected $description = 'Dismiss fee-free reformat drifts and repair bogus reference dates in the Re-Corrections queue.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $scanned = 0;
        $dismissed = 0;
        AddressSupersession::query()
            ->where('status', AddressSupersession::STATUS_PENDING_REVIEW)
            ->where('trigger', AddressSupersession::TRIGGER_REVERIFY_DRIFT)
            ->whereNotNull('old_snapshot')
            ->whereNotNull('new_snapshot')
            ->chunkById(200, function ($events) use (&$scanned, &$dismissed, $dry): void {
                foreach ($events as $event) {
                    $scanned++;
                    if (RecorrectionRules::isFeeFreeReformat($event->old_snapshot ?? [], $event->new_snapshot ?? [])) {
                        if (! $dry) {
                            $event->update(['status' => AddressSupersession::STATUS_DISMISSED]);
                        }
                        $dismissed++;
                    }
                }
            });

        $repaired = 0;
        $nulled = 0;
        AddressSupersession::query()
            ->whereNotNull('reference_date')
            ->whereDate('reference_date', '<', '2005-01-01')
            ->chunkById(200, function ($events) use (&$repaired, &$nulled, $dry): void {
                foreach ($events as $event) {
                    $fixed = RecorrectionRules::sanitizeDate((string) $event->reference_date);
                    if (! $dry) {
                        $event->update(['reference_date' => $fixed]);
                    }
                    $fixed === null ? $nulled++ : $repaired++;
                }
            });

        $this->table(['Action', 'Count'], [
            ['Pending reverify events scanned', $scanned],
            [$dry ? 'Would dismiss (fee-free reformat)' : 'Dismissed (fee-free reformat)', $dismissed],
            [$dry ? 'Would repair dates (00xx → 20xx)' : 'Repaired dates (00xx → 20xx)', $repaired],
            [$dry ? 'Would null implausible dates' : 'Nulled implausible dates', $nulled],
        ]);

        return self::SUCCESS;
    }
}
