<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Services\Invoices\BillingType;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Populate carrier_shipments.billing_type (prepaid | third_party | collect) from signals already
 * stored at import — no PDF re-parse. UPS uses section + is_third_party; FedEx uses is_third_party and
 * the payment-term prefix that (for un-reparsed rows) still lives in the service field. Mutually
 * exclusive conditions, so it's idempotent and safe to re-run.
 */
class BackfillBillingType extends Command
{
    protected $signature = 'carrier:backfill-billing-type {--dry-run : Report counts without writing}';

    protected $description = 'Set carrier_shipments.billing_type (prepaid/third_party/collect) from stored import signals.';

    public function handle(): int
    {
        $ups = (int) (Carrier::where('slug', 'ups')->value('id') ?? 0);
        $fedex = (int) (Carrier::where('slug', 'fedex')->value('id') ?? 0);
        $dry = (bool) $this->option('dry-run');

        $rows = [];

        // ---- UPS: 3rd-party bill-to wins; Inbound section = Collect; else Prepaid ----
        $rows[] = $this->apply('UPS third_party', $dry, BillingType::THIRD_PARTY,
            fn (Builder $q) => $q->where('carrier_id', $ups)->where('is_third_party', true));
        $rows[] = $this->apply('UPS collect', $dry, BillingType::COLLECT,
            fn (Builder $q) => $q->where('carrier_id', $ups)->where('is_third_party', false)->where('section', 'inbound'));
        $rows[] = $this->apply('UPS prepaid', $dry, BillingType::PREPAID,
            fn (Builder $q) => $q->where('carrier_id', $ups)->where('is_third_party', false)
                ->where(fn (Builder $w) => $w->where('section', '<>', 'inbound')->orWhereNull('section')));

        // ---- FedEx: 3rd-party (flag or "Bill ..." service) > Collect (service) > Prepaid ----
        $fxThird = fn (Builder $q) => $q->where('carrier_id', $fedex)
            ->where(fn (Builder $w) => $w->where('is_third_party', true)->orWhere('service', 'like', 'Bill %'));
        $rows[] = $this->apply('FedEx third_party', $dry, BillingType::THIRD_PARTY, $fxThird);
        $rows[] = $this->apply('FedEx collect', $dry, BillingType::COLLECT,
            fn (Builder $q) => $q->where('carrier_id', $fedex)->where('is_third_party', false)
                ->where('service', 'not like', 'Bill %')->where('service', 'like', 'Collect%'));
        $rows[] = $this->apply('FedEx prepaid', $dry, BillingType::PREPAID,
            fn (Builder $q) => $q->where('carrier_id', $fedex)->where('is_third_party', false)
                ->where('service', 'not like', 'Bill %')
                ->where(fn (Builder $w) => $w->where('service', 'not like', 'Collect%')->orWhereNull('service')));

        $this->table(['Segment', $dry ? 'Would set' : 'Set'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  callable(Builder): Builder  $scope
     * @return array{0: string, 1: int}
     */
    private function apply(string $label, bool $dry, string $value, callable $scope): array
    {
        $query = $scope(DB::table('carrier_shipments'));

        return [$label.' → '.$value, $dry ? (int) $query->count() : (int) $query->update(['billing_type' => $value])];
    }
}
