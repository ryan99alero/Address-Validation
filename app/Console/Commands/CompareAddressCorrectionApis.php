<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Carrier;
use App\Services\Carriers\FedExCarrier;
use App\Services\Carriers\SmartyCarrier;
use App\Services\Carriers\UpsCarrier;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers "is Smarty worth paying for over the UPS/FedEx APIs we already have?" — takes real invoice
 * address corrections (the carrier's own after-the-fact fix = ground truth), submits each ORIGINAL
 * (bad) address to Smarty, FedEx XAV and UPS XAV live, and scores whether each API reproduces the
 * correction. Carriers are called directly, which bypasses the local correction cache → live API.
 */
class CompareAddressCorrectionApis extends Command
{
    protected $signature = 'address:compare-apis
        {--per-carrier=50 : bad addresses from each of UPS and FedEx invoices}
        {--units=20 : of the total, how many must involve a secondary unit (Suite/Bldg/Apt)}
        {--sleep=250 : ms between API calls}
        {--skip-smarty : exclude Smarty (avoids spending its paid per-lookup quota)}
        {--select-only : show the selected sample only, make no API calls}';

    protected $description = 'Compare Smarty vs UPS vs FedEx address-correction APIs against real invoice corrections';

    /** @var array<string,string> */
    private array $abbr = [
        'STREET' => 'ST', 'AVENUE' => 'AVE', 'BOULEVARD' => 'BLVD', 'DRIVE' => 'DR', 'ROAD' => 'RD',
        'LANE' => 'LN', 'COURT' => 'CT', 'PLACE' => 'PL', 'CIRCLE' => 'CIR', 'HIGHWAY' => 'HWY',
        'PARKWAY' => 'PKWY', 'TERRACE' => 'TER', 'SUITE' => 'STE', 'BUILDING' => 'BLDG',
        'APARTMENT' => 'APT', 'FLOOR' => 'FL', 'DEPARTMENT' => 'DEPT', 'NORTH' => 'N', 'SOUTH' => 'S',
        'EAST' => 'E', 'WEST' => 'W', 'NORTHEAST' => 'NE', 'NORTHWEST' => 'NW', 'SOUTHEAST' => 'SE',
        'SOUTHWEST' => 'SW',
    ];

    public function handle(): int
    {
        $lines = $this->select((int) $this->option('per-carrier'), (int) $this->option('units'));

        $this->info("Selected {$lines->count()} clean, same-state invoice corrections:");
        $this->table(['Source', 'Count', 'With unit'], $lines->groupBy('slug')->map(fn ($g, $slug) => [
            strtoupper($slug), $g->count(), $g->filter(fn ($l) => filled($l->corrected_address_2) || filled($l->original_address_2))->count(),
        ])->values());
        $this->line('Change types: '.$lines->groupBy('change_type')->map(fn ($g, $t) => "{$t}={$g->count()}")->implode('  '));

        if ($this->option('select-only')) {
            return self::SUCCESS;
        }

        $carriers = [
            'fedex' => (new FedExCarrier)->setCarrier(Carrier::where('slug', 'fedex')->firstOrFail()),
            'ups' => (new UpsCarrier)->setCarrier(Carrier::where('slug', 'ups')->firstOrFail()),
        ];
        if (! $this->option('skip-smarty')) {
            $carriers = ['smarty' => (new SmartyCarrier)->setCarrier(Carrier::where('slug', 'smarty')->firstOrFail())] + $carriers;
        }
        $sleepUs = (int) $this->option('sleep') * 1000;

        $rows = [];
        $bar = $this->output->createProgressBar($lines->count());
        $bar->start();

        foreach ($lines as $line) {
            $truth = $this->canon($line->corrected_address_1, $line->corrected_address_2, $line->corrected_city, $line->corrected_state, $line->corrected_postal);
            $truthZip = $this->zip5($line->corrected_postal);
            $hasUnit = filled($line->corrected_address_2) || filled($line->original_address_2);

            $row = [
                'id' => $line->id,
                'source' => strtoupper($line->slug),
                'change_type' => $line->change_type,
                'has_unit' => $hasUnit ? 'Y' : '',
                'original' => $this->fmt($line->original_address_1, $line->original_address_2, $line->original_city, $line->original_state, $line->original_postal),
                'invoice_correction' => $this->fmt($line->corrected_address_1, $line->corrected_address_2, $line->corrected_city, $line->corrected_state, $line->corrected_postal),
            ];

            $results = [];
            foreach ($carriers as $name => $carrier) {
                $r = $this->runCarrier($carrier, $line, $sleepUs);
                $results[$name] = $r;
                $row[$name] = $r['ok'] ? $this->fmt($r['a1'], $r['a2'], $r['city'], $r['state'], $r['postal']) : 'ERR: '.$r['err'];
                $row[$name.'_match'] = $this->scoreMatch($r, $truth, $truthZip);
            }

            // Simulate the production fallback chains from the SAME per-carrier calls
            // (no extra API cost): the first carrier that returns a usable result
            // (ok + STATUS_VALID + an address line) claims the address; otherwise the
            // next carrier's result is used, mirroring validateBatchWithEngine().
            foreach (['fedex_ups' => ['fedex', 'ups'], 'ups_fedex' => ['ups', 'fedex']] as $engine => $order) {
                $chosen = $this->chooseChainResult($results, $order);
                $row[$engine.'_match'] = $this->scoreMatch($chosen['result'], $truth, $truthZip);
                $row[$engine.'_used'] = $chosen['used'];
            }

            $rows[] = $row;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $path = storage_path('app/address-api-comparison-'.now()->format('Ymd-His').'.csv');
        $this->writeCsv($path, $rows);
        $this->summary($rows);
        $this->info("Full per-address results: {$path}");

        return self::SUCCESS;
    }

    /** @return array{ok:bool,err:?string,status:?string,a1:?string,a2:?string,city:?string,state:?string,postal:?string} */
    private function runCarrier(object $carrier, object $line, int $sleepUs): array
    {
        $address = Address::create([
            'input_address_1' => $line->original_address_1,
            'input_address_2' => $line->original_address_2,
            'input_city' => $line->original_city,
            'input_state' => $line->original_state,
            'input_postal' => $line->original_postal,
            'input_country' => $line->original_country ?: 'US',
            'validation_status' => 'pending',
        ]);

        try {
            $carrier->validateAddress($address);
            $address->refresh();
            $result = ['ok' => true, 'err' => null, 'status' => $address->validation_status, 'a1' => $address->output_address_1, 'a2' => $address->output_address_2,
                'city' => $address->output_city, 'state' => $address->output_state, 'postal' => $address->output_postal];
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'err' => substr($e->getMessage(), 0, 100), 'status' => null, 'a1' => null, 'a2' => null, 'city' => null, 'state' => null, 'postal' => null];
        } finally {
            $address->delete();
        }

        usleep($sleepUs);

        return $result;
    }

    /**
     * Score one carrier result against the invoice truth: FULL (reproduced the
     * correction), ZIP (matched zip5 only), NO (returned something else), ERR.
     *
     * @param  array{ok:bool,a1:?string,a2:?string,city:?string,state:?string,postal:?string}  $r
     */
    private function scoreMatch(array $r, string $truth, string $truthZip): string
    {
        if (! $r['ok']) {
            return 'ERR';
        }

        if ($this->canon($r['a1'], $r['a2'], $r['city'], $r['state'], $r['postal']) === $truth) {
            return 'FULL';
        }

        return ($truthZip !== '' && $this->zip5($r['postal']) === $truthZip) ? 'ZIP' : 'NO';
    }

    /**
     * Simulate a fallback chain over already-collected per-carrier results: the
     * first carrier that returned a usable result (ok + STATUS_VALID + an address
     * line) claims it; otherwise the last carrier's result stands. Mirrors
     * AddressValidationService::validateBatchWithEngine().
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<int, string>  $order
     * @return array{result: array<string, mixed>, used: string}
     */
    private function chooseChainResult(array $results, array $order): array
    {
        foreach ($order as $slug) {
            $r = $results[$slug];
            if ($r['ok'] && ($r['status'] ?? null) === Address::STATUS_VALID && filled($r['a1'])) {
                return ['result' => $r, 'used' => $slug];
            }
        }

        $last = $order[count($order) - 1];

        return ['result' => $results[$last], 'used' => $last];
    }

    private function select(int $perCarrier, int $units): Collection
    {
        $unitsPer = intdiv($units, 2);
        $ids = collect();

        foreach (['ups', 'fedex'] as $slug) {
            $withUnit = $this->cleanBase($slug)
                ->whereNotNull('l.corrected_address_2')->where('l.corrected_address_2', '!=', '')
                ->inRandomOrder()->limit($unitsPer)->pluck('l.id');

            $rest = $this->cleanBase($slug)
                ->where(fn ($w) => $w->whereNull('l.corrected_address_2')->orWhere('l.corrected_address_2', '=', ''))
                ->inRandomOrder()->limit($perCarrier - $withUnit->count())->pluck('l.id');

            $ids = $ids->merge($withUnit)->merge($rest);
        }

        return collect(DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as i', 'i.id', '=', 'l.carrier_invoice_id')
            ->join('carriers as c', 'c.id', '=', 'i.carrier_id')
            ->whereIn('l.id', $ids->all())
            ->select('l.*', 'c.slug')
            ->get());
    }

    private function cleanBase(string $slug): Builder
    {
        return DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as i', 'i.id', '=', 'l.carrier_invoice_id')
            ->join('carriers as c', 'c.id', '=', 'i.carrier_id')
            ->where('c.slug', $slug)
            ->whereNotNull('l.corrected_address_1')->where('l.corrected_address_1', '!=', '')
            ->whereNotNull('l.original_address_1')->where('l.original_address_1', '!=', '')
            ->whereColumn('l.original_state', 'l.corrected_state'); // drop cross-state mis-parses
    }

    private function summary(array $rows): void
    {
        $buckets = [
            'ALL' => fn ($r) => true,
            'UNIT cases' => fn ($r) => $r['has_unit'] === 'Y',
            'ZIP fixes' => fn ($r) => $r['change_type'] === 'zip_changed',
            'STREET fixes' => fn ($r) => in_array($r['change_type'], ['street_renamed', 'street_number_changed'], true),
        ];

        foreach ($buckets as $label => $filter) {
            $set = array_filter($rows, $filter);
            if (! $set) {
                continue;
            }
            $n = count($set);
            $this->newLine();
            $this->line("<comment>{$label} (n={$n})</comment> — FULL = reproduced the invoice correction; ZIP = matched zip5 only; Fell-through = chain used its 2nd carrier");
            $sample = $set[array_key_first($set)];
            $engines = array_values(array_filter(
                ['smarty', 'fedex', 'ups', 'fedex_ups', 'ups_fedex'],
                fn ($c) => array_key_exists($c.'_match', $sample)
            ));

            $stats = [];
            foreach ($engines as $c) {
                $full = count(array_filter($set, fn ($r) => ($r[$c.'_match'] ?? null) === 'FULL'));
                $zip = count(array_filter($set, fn ($r) => ($r[$c.'_match'] ?? null) === 'ZIP'));
                $err = count(array_filter($set, fn ($r) => ($r[$c.'_match'] ?? null) === 'ERR'));

                // For chains: how often the second carrier was actually used.
                $fellThrough = '';
                if (str_contains($c, '_')) {
                    $second = explode('_', $c)[1];
                    $fellThrough = (string) count(array_filter($set, fn ($r) => ($r[$c.'_used'] ?? null) === $second));
                }

                $stats[] = [strtoupper($c), $full.' ('.round($full / $n * 100).'%)', $zip, $err, $fellThrough];
            }
            $this->table(['Engine', 'FULL match', 'ZIP-only', 'ERR', 'Fell-through'], $stats);
        }
    }

    private function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, array_keys($rows[0] ?? ['id' => null]));
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);
    }

    private function fmt(?string $a1, ?string $a2, ?string $city, ?string $state, ?string $postal): string
    {
        return trim(trim(($a1 ?? '').' '.($a2 ?? '')).', '.trim(($city ?? '').' '.($state ?? '').' '.($postal ?? '')), ', ');
    }

    /** Canonical form for equality: abbreviated, punctuation-stripped street+unit + city + state + zip5. */
    private function canon(?string $a1, ?string $a2, ?string $city, ?string $state, ?string $postal): string
    {
        $street = strtoupper(trim(($a1 ?? '').' '.($a2 ?? '')));
        $street = preg_replace('/[^A-Z0-9 ]/', ' ', $street);
        $street = implode(' ', array_map(fn ($w) => $this->abbr[$w] ?? $w, explode(' ', preg_replace('/\s+/', ' ', trim($street)))));

        return trim($street).'|'.strtoupper(trim($city ?? '')).'|'.strtoupper(trim($state ?? '')).'|'.$this->zip5($postal);
    }

    private function zip5(?string $postal): string
    {
        return substr(preg_replace('/[^0-9]/', '', $postal ?? ''), 0, 5);
    }
}
