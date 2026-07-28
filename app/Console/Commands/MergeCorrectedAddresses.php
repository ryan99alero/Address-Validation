<?php

namespace App\Console\Commands;

use App\Models\AddressVariant;
use App\Models\CorrectedAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consolidates duplicate "good" (corrected) address records for one physical address into a single
 * canonical record with the correct ZIP. Carriers sometimes return conflicting corrected ZIPs for
 * the same street (e.g. Irvine's 1996 ZIP reshuffle left 14431 Culver Dr corrected to 92604, 92612
 * and 92614 across shipments), which fractures one address into several corrected_addresses rows.
 * This re-points every variant / invoice line / candidate onto the canonical record, fixes its ZIP
 * (+ optional ZIP+4), recomputes its counts, then deletes the emptied duplicates. All in one
 * transaction; --dry-run reports the plan without writing.
 */
class MergeCorrectedAddresses extends Command
{
    protected $signature = 'correction-cache:merge-good
        {--address1= : Street (address line 1) to match, e.g. "14431 CULVER DR"}
        {--city= : City to match, e.g. "Irvine"}
        {--state= : State to match, e.g. "CA"}
        {--postal= : Target canonical base ZIP, e.g. 92604}
        {--ext= : Target canonical ZIP+4 extension, e.g. 0305}
        {--dry-run : Show the merge plan without changing anything}';

    protected $description = 'Merge duplicate good/corrected address records for one street into a single canonical ZIP';

    public function handle(): int
    {
        $address1 = $this->option('address1');
        $city = $this->option('city');
        $state = $this->option('state');
        $postalOpt = $this->option('postal');

        if ($address1 === null || $city === null || $state === null || $postalOpt === null) {
            $this->error('--address1, --city, --state and --postal are all required.');

            return self::FAILURE;
        }

        $address1 = CorrectedAddress::normalize($address1);
        $city = CorrectedAddress::normalize($city);
        $state = CorrectedAddress::normalize($state);
        $targetBase = CorrectedAddress::normalizePostal($postalOpt);
        $targetExt = $this->option('ext') !== null ? CorrectedAddress::normalizePostal($this->option('ext')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $matches = CorrectedAddress::query()
            ->where('address_1', $address1)
            ->where('city', $city)
            ->where('state', $state)
            ->get();

        if ($matches->isEmpty()) {
            $this->error("No corrected_addresses match \"{$address1}, {$city}, {$state}\".");

            return self::FAILURE;
        }

        $canonical = $this->chooseCanonical($matches, $targetBase);
        $sources = $matches->reject(fn (CorrectedAddress $c): bool => $c->id === $canonical->id)->values();

        $this->info('Match: '.$address1.', '.$city.', '.$state);
        $this->line('  Canonical -> Rec '.$canonical->id.' (ZIP '.$canonical->postal.') will become '.$targetBase.($targetExt ? '-'.$targetExt : ''));
        foreach ($matches as $c) {
            $this->line(sprintf('    Rec %d | ZIP %-6s | variants=%d | invoice_lines=%d | candidates=%d%s',
                $c->id, $c->postal,
                $c->variants()->count(),
                DB::table('carrier_invoice_lines')->where('corrected_address_id', $c->id)->count(),
                DB::table('address_candidates')->where('corrected_address_id', $c->id)->count(),
                $c->id === $canonical->id ? '  <- CANONICAL' : '  -> merge & delete'));
        }

        if ($sources->isEmpty() && $canonical->postal === $targetBase && (string) $canonical->postal_ext === (string) $targetExt) {
            $this->info('Nothing to do — already a single canonical record with the target ZIP.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no changes written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($canonical, $sources, $targetBase, $targetExt, $matches): void {
            foreach ($sources as $source) {
                AddressVariant::repointAll($source->id, $canonical->id);
                DB::table('carrier_invoice_lines')->where('corrected_address_id', $source->id)
                    ->update(['corrected_address_id' => $canonical->id]);
                DB::table('address_candidates')->where('corrected_address_id', $source->id)
                    ->update(['corrected_address_id' => $canonical->id]);
                // Keep any chain + verification rows coherent when a superseded/source row is deleted.
                DB::table('corrected_addresses')->where('superseded_by_id', $source->id)
                    ->update(['superseded_by_id' => $canonical->id]);
                $this->coalesceVerifications($source->id, $canonical->id);
            }

            $canonical->postal = $targetBase;
            $canonical->postal_ext = $targetExt;
            $canonical->address_hash = CorrectedAddress::computeHash(
                $canonical->address_1, $canonical->city, $canonical->state, $targetBase, $canonical->country ?? 'us'
            );
            $canonical->usage_count = (int) $matches->sum('usage_count');
            $canonical->save();

            foreach ($sources as $source) {
                $source->delete();
            }

            $canonical->update(['variant_count' => $canonical->variants()->count()]);
        });

        $canonical->refresh();
        $this->info('Merged '.$sources->count().' duplicate(s) into Rec '.$canonical->id
            .' ('.$canonical->postal.($canonical->postal_ext ? '-'.$canonical->postal_ext : '')
            .'). variants='.$canonical->variant_count.', invoice_lines='.$canonical->invoiceLines()->count().'.');

        return self::SUCCESS;
    }

    /**
     * Prefer an existing record already on the target base ZIP (most-used wins); otherwise the
     * most-used record overall, whose ZIP we'll rewrite to the target.
     *
     * @param  Collection<int, CorrectedAddress>  $matches
     */
    private function chooseCanonical($matches, string $targetBase): CorrectedAddress
    {
        $onTarget = $matches->where('postal', $targetBase)->sortByDesc('usage_count');

        return $onTarget->first() ?? $matches->sortByDesc('usage_count')->first();
    }

    /**
     * Move a source record's per-carrier verification rows to the canonical, keeping the newest
     * verified_at per carrier (the unique (corrected_address_id, carrier_id) means at most one each).
     */
    private function coalesceVerifications(int $fromId, int $toId): void
    {
        foreach (DB::table('address_verifications')->where('corrected_address_id', $fromId)->get() as $ver) {
            $existing = DB::table('address_verifications')
                ->where('corrected_address_id', $toId)->where('carrier_id', $ver->carrier_id)->first();

            if ($existing === null) {
                DB::table('address_verifications')->where('id', $ver->id)
                    ->update(['corrected_address_id' => $toId]);

                continue;
            }

            if ($ver->verified_at !== null && ($existing->verified_at === null || $ver->verified_at > $existing->verified_at)) {
                DB::table('address_verifications')->where('id', $existing->id)->update([
                    'status' => $ver->status, 'verified_at' => $ver->verified_at,
                    'checked_at' => $ver->checked_at, 'source' => $ver->source, 'updated_at' => now(),
                ]);
            }
            DB::table('address_verifications')->where('id', $ver->id)->delete();
        }
    }
}
