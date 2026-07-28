<?php

namespace App\Console\Commands;

use App\Jobs\ReverifyCorrectedAddress;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Nightly (or on-demand) re-verification of stale cached good addresses against the carrier that
 * charges the fees. Picks the most-used, longest-unconfirmed addresses per carrier, capped so a large
 * backlog drains over time instead of API-storming, and spaces the dispatched jobs to respect the
 * carrier's per-minute rate limit. Fresh-verified or recently-attempted addresses are skipped.
 */
class ReverifyCorrectionCache extends Command
{
    protected $signature = 'correction-cache:reverify
        {--carrier= : Limit to one carrier slug (ups, fedex)}
        {--limit= : Override the per-carrier daily cap}
        {--sync : Run the checks inline instead of queueing (one-off / debugging)}';

    protected $description = 'Re-verify stale cached good addresses against the carrier validation API';

    public function handle(): int
    {
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('correction_cache.verification_daily_limit', 50);

        if ($limit <= 0) {
            $this->info('Reverify is disabled (verification_daily_limit = 0).');

            return self::SUCCESS;
        }

        $maxAgeDays = (int) config('correction_cache.verification_max_age_days', 365);
        $cutoff = now()->subDays($maxAgeDays);
        $backoff = now()->subDays(7);

        $carriers = Carrier::query()->active()->whereIn('slug', ['ups', 'fedex'])
            ->when($this->option('carrier'), fn ($q) => $q->where('slug', $this->option('carrier')))
            ->get();

        $sync = (bool) $this->option('sync');
        $total = 0;

        foreach ($carriers as $carrier) {
            $candidates = CorrectedAddress::query()
                ->whereNull('superseded_by_id')
                ->where('country', 'us')
                ->whereNotExists(function (Builder $q) use ($carrier, $cutoff, $backoff): void {
                    $q->from('address_verifications as av')
                        ->whereColumn('av.corrected_address_id', 'corrected_addresses.id')
                        ->where('av.carrier_id', $carrier->id)
                        ->where(function (Builder $q) use ($cutoff, $backoff): void {
                            $q->where(fn (Builder $q) => $q->where('av.status', 'verified')->where('av.verified_at', '>=', $cutoff))
                                ->orWhere('av.checked_at', '>=', $backoff);
                        });
                })
                ->orderByDesc('usage_count')
                ->orderByDesc('last_used_at')
                ->limit($limit)
                ->pluck('id');

            $spacingSeconds = 60 / max(1, (int) ($carrier->rate_limit_per_minute ?: 30));

            foreach ($candidates as $i => $addressId) {
                if ($sync) {
                    dispatch_sync(new ReverifyCorrectedAddress($addressId, $carrier->id));
                } else {
                    ReverifyCorrectedAddress::dispatch($addressId, $carrier->id)
                        ->onQueue('address-verify')
                        ->delay(now()->addSeconds((int) round($i * $spacingSeconds)));
                }
            }

            $this->info("{$carrier->slug}: ".($sync ? 'checked ' : 'queued ').$candidates->count().' address(es).');
            $total += $candidates->count();
        }

        $this->info(($sync ? 'Checked ' : 'Queued ')."{$total} address(es) for reverification.");

        return self::SUCCESS;
    }
}
