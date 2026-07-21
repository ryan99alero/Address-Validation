<?php

namespace App\Console\Commands;

use App\Models\ChargebackPush;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill the stable `txn_id` identity onto existing chargeback ledger rows and flag the cross-import
 * duplicates the old ship_date-based key let through. Rows are grouped by their recomputed identity; the
 * earliest row in a group (or one that already carries a txn_id from a live push) is canonical and keeps
 * the txn_id, and every other row in the group is a duplicate — pointed at the canonical via
 * duplicate_of_id, and marked reversal_state = needs_reversal when it was actually pushed to Pace.
 * Idempotent and re-runnable; --dry-run reports without writing.
 */
class BackfillChargebackIdentity extends Command
{
    protected $signature = 'chargebacks:backfill-identity {--dry-run : Report what would change without writing}';

    protected $description = 'Assign the stable txn_id to chargeback rows and flag cross-import duplicates for reversal';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Pull invoice number/date alongside each ledger row so the identity matches the live path exactly.
        $rows = ChargebackPush::query()
            ->leftJoin('carrier_invoices as i', 'i.id', '=', 'chargeback_pushes.carrier_invoice_id')
            ->select('chargeback_pushes.*', 'i.invoice_number as inv_number', 'i.invoice_date as inv_date')
            ->orderBy('chargeback_pushes.created_at')->orderBy('chargeback_pushes.id')
            ->get();

        $groups = $rows->groupBy(fn (ChargebackPush $r): string => ChargebackPush::identity([
            'carrier_id' => $r->carrier_id, 'tracking_number' => $r->tracking_number,
            'activity_code' => $r->activity_code, 'amount' => $r->amount,
            'invoice_number' => $r->inv_number, 'invoice_date' => $r->inv_date,
        ]));

        $keyed = 0;
        $dupGroups = 0;
        $dupRows = 0;
        $needReversal = 0;
        $reversalDollars = 0.0;

        foreach ($groups as $identity => $group) {
            /** @var Collection<int, ChargebackPush> $group */
            $canonical = $group->first(fn (ChargebackPush $r): bool => $r->txn_id !== null) ?? $group->first();

            if (! $dryRun && $canonical->txn_id !== $identity) {
                $canonical->update(['txn_id' => $identity]);
            }
            $keyed++;

            $dupes = $group->reject(fn (ChargebackPush $r): bool => $r->id === $canonical->id);
            if ($dupes->isNotEmpty()) {
                $dupGroups++;
            }

            foreach ($dupes as $d) {
                $dupRows++;
                $needs = $d->status === ChargebackPush::STATUS_PUSHED;
                if ($needs) {
                    $needReversal++;
                    $reversalDollars += (float) $d->amount;
                }
                if (! $dryRun) {
                    $d->update([
                        'duplicate_of_id' => $canonical->id,
                        'reversal_state' => $needs ? ChargebackPush::REVERSAL_NEEDS : null,
                    ]);
                }
                $this->line(sprintf('  dup cb#%d ($%s, %s) -> canonical cb#%d%s',
                    $d->id, number_format((float) $d->amount, 2), $d->status, $canonical->id, $needs ? ' [needs_reversal]' : ''));
            }
        }

        $verb = $dryRun ? '[DRY RUN] Would key' : 'Keyed';
        $this->info("{$verb} {$rows->count()} rows into {$keyed} identities.");
        $this->info("Duplicate groups: {$dupGroups}; duplicate rows: {$dupRows}; pushed duplicates needing reversal: {$needReversal} (\$".number_format($reversalDollars, 2).').');

        return self::SUCCESS;
    }
}
