<?php

namespace App\Console\Commands;

use App\Models\AddressSupersession;
use App\Models\AddressVariant;
use App\Models\CorrectedAddress;
use App\Services\Invoices\CorrectionGuard;
use App\Services\Invoices\CorrectionThreader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair of addresses that are simultaneously a GOOD record and a BAD variant pointing
 * elsewhere — i.e. an address we hold as good that the carrier later re-corrected, but which never
 * threaded through. Classifies each via CorrectionGuard and either threads it (supersede + re-point),
 * flags it for human review, or — for garbage corrections (carrier "fixed" it to a different state /
 * our own dock / nonsense) — deactivates the poisoning variant. --dry-run reports the plan only.
 */
class BackfillCorrectionChains extends Command
{
    protected $signature = 'correction-cache:backfill-chains
        {--limit=0 : Max pairs to process (0 = all)}
        {--dry-run : Classify and report without changing anything}';

    protected $description = 'Thread / review / deactivate the addresses that were re-corrected but never chained';

    public function handle(CorrectionThreader $threader): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // A good row A whose OWN address (postal+hash, hits variant_postal_hash_unique) also exists as
        // a bad variant pointing at a different good record = A was itself re-corrected.
        $pairs = DB::table('corrected_addresses as ca')
            ->join('address_variants as av', function ($join): void {
                $join->on('av.input_postal', '=', 'ca.postal')->on('av.input_hash', '=', 'ca.address_hash');
            })
            ->whereColumn('av.corrected_address_id', '<>', 'ca.id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->orderByDesc('av.times_seen')
            ->get(['ca.id as a_id', 'av.id as variant_id', 'av.corrected_address_id as g_id']);

        $this->info('Re-corrected addresses to classify: '.$pairs->count().($dryRun ? '  (DRY RUN)' : ''));

        $guard = new CorrectionGuard;
        $counts = ['threaded' => 0, 'review' => 0, 'garbage' => 0, 'mutual' => 0, 'skipped' => 0];
        $samples = ['threaded' => [], 'review' => [], 'garbage' => []];

        foreach ($pairs as $pair) {
            $a = CorrectedAddress::find($pair->a_id);
            $variant = AddressVariant::find($pair->variant_id);
            $g = CorrectedAddress::find($pair->g_id);

            if ($a === null || $variant === null || $g === null || $a->isSuperseded()) {
                $counts['skipped']++;

                continue;
            }

            $terminal = $g->resolveTerminal();
            if ($terminal->id === $a->id) { // mutual / cycle — never auto-resolve
                $counts['mutual']++;
                if (! $dryRun) {
                    $threader->recordEvent($a, $g, AddressSupersession::TRIGGER_BACKFILL, AddressSupersession::STATUS_PENDING_REVIEW);
                }

                continue;
            }

            $verdict = $guard->evaluate($this->form($a), $this->form($terminal));
            $label = sprintf('%s %s %s → %s %s (%s%s)',
                $a->address_1, $a->state, $a->postal, $terminal->address_1, $terminal->postal,
                $verdict['reason'], $verdict['distance_miles'] !== null ? ', '.$verdict['distance_miles'].'mi' : '');

            switch ($verdict['verdict']) {
                case CorrectionGuard::APPLY:
                    $counts['threaded']++;
                    $samples['threaded'][] = $label;
                    if (! $dryRun) {
                        $threader->thread($a, $terminal, [
                            'trigger' => AddressSupersession::TRIGGER_BACKFILL,
                            'guard_result' => $verdict,
                        ]);
                    }
                    break;

                case CorrectionGuard::REVIEW:
                    $counts['review']++;
                    $samples['review'][] = $label;
                    if (! $dryRun) {
                        $threader->recordEvent($a, $terminal, AddressSupersession::TRIGGER_BACKFILL,
                            AddressSupersession::STATUS_PENDING_REVIEW, ['guard_result' => $verdict]);
                    }
                    break;

                case CorrectionGuard::REJECT:
                    $counts['garbage']++;
                    $samples['garbage'][] = $label;
                    if (! $dryRun) {
                        $variant->update([
                            'is_active' => false,
                            'inactive_reason' => 'Garbage carrier correction ('.$verdict['reason'].', backfill)',
                        ]);
                        $threader->recordEvent($a, $terminal, AddressSupersession::TRIGGER_BACKFILL,
                            AddressSupersession::STATUS_REJECTED_GARBAGE, ['guard_result' => $verdict]);
                    }
                    break;
            }
        }

        $this->newLine();
        $this->table(['Class', 'Count', 'Action'], [
            ['Threaded (auto)', $counts['threaded'], 'superseded + variants re-pointed'],
            ['Review', $counts['review'], 'pending_review event, no change'],
            ['Garbage', $counts['garbage'], 'variant deactivated + rejected event'],
            ['Mutual/cycle', $counts['mutual'], 'pending_review event, no change'],
            ['Skipped', $counts['skipped'], 'already superseded / missing row'],
        ]);

        foreach (['garbage', 'review', 'threaded'] as $class) {
            if ($samples[$class] !== []) {
                $this->newLine();
                $this->line('<comment>'.strtoupper($class).' samples:</comment>');
                foreach (array_slice($samples[$class], 0, 8) as $s) {
                    $this->line('  '.$s);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{address_1: ?string, city: ?string, state: ?string, postal: ?string}
     */
    private function form(CorrectedAddress $a): array
    {
        return ['address_1' => $a->address_1, 'city' => $a->city, 'state' => $a->state, 'postal' => $a->postal];
    }
}
