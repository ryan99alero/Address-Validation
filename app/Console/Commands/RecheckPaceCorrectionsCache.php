<?php

namespace App\Console\Commands;

use App\Models\AddressVariant;
use App\Models\CorrectedAddress;
use App\Models\SystemLog;
use Illuminate\Console\Command;

/**
 * Recheck Pace address corrections that were resolved live by the FedEx API against the local
 * correction cache. Every imported carrier invoice feeds that cache (original -> corrected variants),
 * so a correction FedEx charged us for at request time may now be served for free from the cache. For
 * each fedex_api-sourced Pace correction, this looks the ORIGINAL address up in the cache using the
 * same hash production uses (no usage-stat side effect); on a hit whose cached correction matches the
 * one FedEx applied, it re-tags the log's source to local_cache (keeping previous_source). Every row
 * checked is stamped metadata->rechecked_at so a later --all pass only processes the remainder.
 */
class RecheckPaceCorrectionsCache extends Command
{
    protected $signature = 'pace:recheck-cache
        {--limit=500 : How many un-rechecked fedex_api corrections to process}
        {--all : Process every remaining un-rechecked fedex_api correction (ignores --limit)}
        {--dry-run : Report the outcome without writing any changes}';

    protected $description = 'Recheck FedEx-API Pace corrections against the local cache; re-tag cache-covered ones to local_cache';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = SystemLog::query()
            ->where('type', 'pace_address_correction')
            ->where('metadata->source', 'fedex_api')
            ->whereNull('metadata->rechecked_at')
            ->orderBy('id');

        $remaining = (clone $query)->count();
        if (! $this->option('all')) {
            $query->limit(max(1, (int) $this->option('limit')));
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No un-rechecked fedex_api Pace corrections remain.');

            return self::SUCCESS;
        }

        $tally = ['cache_hit' => 0, 'cache_hit_diff' => 0, 'miss' => 0, 'no_address' => 0];

        foreach ($rows as $log) {
            $result = $this->recheck($log, $dryRun);
            $tally[$result]++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Rechecked {$rows->count()} of {$remaining} remaining fedex_api corrections:");
        $this->table(['Outcome', 'Count', 'Meaning'], [
            ['cache_hit → local_cache', $tally['cache_hit'], $dryRun ? 'would re-tag (cache covers it, same correction)' : 're-tagged to local_cache'],
            ['cache_hit_diff', $tally['cache_hit_diff'], 'original in cache but a DIFFERENT correction — left as fedex_api, flagged'],
            ['miss', $tally['miss'], 'not in cache — left as fedex_api'],
            ['no_address', $tally['no_address'], 'no original address on the log (no-change) — skipped'],
        ]);
        $left = $remaining - $rows->count();
        $this->line($left > 0 ? "{$left} still un-rechecked — re-run with --all to finish." : 'All fedex_api corrections have now been rechecked.');

        return self::SUCCESS;
    }

    /**
     * @return 'cache_hit'|'cache_hit_diff'|'miss'|'no_address'
     */
    private function recheck(SystemLog $log, bool $dryRun): string
    {
        $meta = $log->metadata ?? [];
        $orig = $meta['original'] ?? null;
        $corr = $meta['corrected'] ?? null;

        if (! is_array($orig) || blank($orig['address1'] ?? null) || blank($orig['zip'] ?? null)) {
            $this->stamp($log, $meta, 'no_address', null, $dryRun);

            return 'no_address';
        }

        $cached = $this->cacheLookup(
            (string) $orig['address1'],
            $orig['city'] ?? null,
            $orig['state'] ?? null,
            (string) $orig['zip'],
            $orig['country'] ?? 'us'
        );

        if ($cached === null) {
            $this->stamp($log, $meta, 'miss', null, $dryRun);

            return 'miss';
        }

        $sameCorrection = is_array($corr)
            && $this->canonCache($cached) === $this->canonMeta($corr);

        $result = $sameCorrection ? 'cache_hit' : 'cache_hit_diff';
        $this->stamp($log, $meta, $result, $cached->id, $dryRun);

        return $result;
    }

    /**
     * Local-cache lookup by the SAME hash production uses, but WITHOUT AddressVariant::lookup()'s
     * usage-stat increments — a bulk recheck must not inflate the cache's real hit counters.
     */
    private function cacheLookup(string $address1, ?string $city, ?string $state, string $postal, ?string $country): ?CorrectedAddress
    {
        $hash = AddressVariant::computeHash($address1, $city, $state, $postal, $country ?? 'us');

        $variant = AddressVariant::query()
            ->where('input_postal', CorrectedAddress::normalizePostal($postal))
            ->where('input_hash', $hash)
            ->where('is_active', true)
            ->with('correctedAddress')
            ->first();

        return $variant?->correctedAddress;
    }

    private function canonCache(CorrectedAddress $c): string
    {
        return $this->canon($c->address_1, $c->address_2, $c->city, $c->state,
            $c->postal.($c->postal_ext ? '-'.$c->postal_ext : ''));
    }

    /**
     * @param  array<string, mixed>  $corr
     */
    private function canonMeta(array $corr): string
    {
        return $this->canon($corr['address1'] ?? null, $corr['address2'] ?? null,
            $corr['city'] ?? null, $corr['state'] ?? null, $corr['zip'] ?? null);
    }

    private function canon(?string $a1, ?string $a2, ?string $city, ?string $state, ?string $postal): string
    {
        return implode('|', [
            CorrectedAddress::normalize($a1),
            CorrectedAddress::normalize($a2),
            CorrectedAddress::normalize($city),
            CorrectedAddress::normalize($state),
            CorrectedAddress::normalizePostal($postal),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function stamp(SystemLog $log, array $meta, string $result, ?int $correctedId, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $meta['rechecked_at'] = now()->toIso8601String();
        $meta['recheck_result'] = $result;

        if ($result === 'cache_hit') {
            $meta['previous_source'] = $meta['source'] ?? null;
            $meta['source'] = 'local_cache';
            $meta['recheck_corrected_address_id'] = $correctedId;
        }

        $log->update(['metadata' => $meta]);
    }
}
