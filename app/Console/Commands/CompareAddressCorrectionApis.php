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
use Postal\Parser;

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
        {--normalize : preprocess each address through libpostal before validating (Arm B)}
        {--from-csv= : reuse the exact line ids from a prior run CSV (same 100 for a fair A/B)}
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

        $normalize = (bool) $this->option('normalize');
        if ($normalize && ! class_exists(Parser::class)) {
            $this->error('libpostal not loaded. Run with:  php -d extension=postal.so artisan address:compare-apis --normalize ...');

            return self::FAILURE;
        }

        $carriers = [
            'smarty' => (new SmartyCarrier)->setCarrier(Carrier::where('slug', 'smarty')->firstOrFail()),
            'fedex' => (new FedExCarrier)->setCarrier(Carrier::where('slug', 'fedex')->firstOrFail()),
            'ups' => (new UpsCarrier)->setCarrier(Carrier::where('slug', 'ups')->firstOrFail()),
        ];
        $sleepUs = (int) $this->option('sleep') * 1000;

        $rows = [];
        $bar = $this->output->createProgressBar($lines->count());
        $bar->start();

        foreach ($lines as $line) {
            $truth = $this->canon($line->corrected_address_1, $line->corrected_address_2, $line->corrected_city, $line->corrected_state, $line->corrected_postal);
            $truthZip = $this->zip5($line->corrected_postal);
            $hasUnit = filled($line->corrected_address_2) || filled($line->original_address_2);

            $input = $normalize ? $this->libpostalNormalize($line) : [
                'a1' => $line->original_address_1, 'a2' => $line->original_address_2, 'city' => $line->original_city,
                'state' => $line->original_state, 'postal' => $line->original_postal, 'country' => $line->original_country ?: 'US',
            ];

            $row = [
                'id' => $line->id,
                'source' => strtoupper($line->slug),
                'change_type' => $line->change_type,
                'has_unit' => $hasUnit ? 'Y' : '',
                'original' => $this->fmt($line->original_address_1, $line->original_address_2, $line->original_city, $line->original_state, $line->original_postal),
                'fed_input' => $this->fmt($input['a1'], $input['a2'], $input['city'], $input['state'], $input['postal']),
                'invoice_correction' => $this->fmt($line->corrected_address_1, $line->corrected_address_2, $line->corrected_city, $line->corrected_state, $line->corrected_postal),
            ];

            foreach ($carriers as $name => $carrier) {
                $r = $this->runCarrier($carrier, $input, $sleepUs);
                $row[$name] = $r['ok'] ? $this->fmt($r['a1'], $r['a2'], $r['city'], $r['state'], $r['postal']) : 'ERR: '.$r['err'];
                $row[$name.'_match'] = ! $r['ok'] ? 'ERR'
                    : ($this->canon($r['a1'], $r['a2'], $r['city'], $r['state'], $r['postal']) === $truth ? 'FULL'
                        : (($truthZip !== '' && $this->zip5($r['postal']) === $truthZip) ? 'ZIP' : 'NO'));
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

    /**
     * @param  array{a1:?string,a2:?string,city:?string,state:?string,postal:?string,country:?string}  $input
     * @return array{ok:bool,err:?string,a1:?string,a2:?string,city:?string,state:?string,postal:?string}
     */
    private function runCarrier(object $carrier, array $input, int $sleepUs): array
    {
        $address = Address::create([
            'input_address_1' => $input['a1'],
            'input_address_2' => $input['a2'],
            'input_city' => $input['city'],
            'input_state' => $input['state'],
            'input_postal' => $input['postal'],
            'input_country' => $input['country'] ?: 'US',
            'validation_status' => 'pending',
        ]);

        try {
            $carrier->validateAddress($address);
            $address->refresh();
            $result = ['ok' => true, 'err' => null, 'a1' => $address->output_address_1, 'a2' => $address->output_address_2,
                'city' => $address->output_city, 'state' => $address->output_state, 'postal' => $address->output_postal];
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'err' => substr($e->getMessage(), 0, 100), 'a1' => null, 'a2' => null, 'city' => null, 'state' => null, 'postal' => null];
        } finally {
            $address->delete();
        }

        usleep($sleepUs);

        return $result;
    }

    /**
     * Arm B: re-parse the (often mangled) invoice address with libpostal, then rebuild clean input
     * fields — crucially with the unit in its OWN field instead of jammed into the street line.
     *
     * @return array{a1:string,a2:?string,city:string,state:?string,postal:?string,country:string}
     */
    private function libpostalNormalize(object $line): array
    {
        $raw = trim(implode(' ', array_filter([
            $line->original_address_1, $line->original_address_2, $line->original_city, $line->original_state, $line->original_postal,
        ])));

        $c = [];
        foreach (Parser::parse_address($raw) as $part) {
            $c[$part['label']][] = $part['value'];
        }
        $first = fn (string $k): ?string => isset($c[$k]) ? trim($c[$k][0]) : null;
        $join = fn (string $k): ?string => isset($c[$k]) ? trim(implode(' ', $c[$k])) : null;

        $street = trim(implode(' ', array_filter([$first('house_number'), $join('road')])));
        $unit = $join('unit');

        return [
            'a1' => strtoupper($street ?: (string) $line->original_address_1),
            'a2' => $unit ? strtoupper($unit) : null,
            'city' => strtoupper((string) ($first('city') ?? $line->original_city)),
            'state' => $this->stateCode($first('state') ?? (string) $line->original_state),
            'postal' => $first('postcode') ?? $line->original_postal,
            'country' => 'US',
        ];
    }

    private function stateCode(?string $state): ?string
    {
        if (! $state) {
            return null;
        }
        $s = strtoupper(trim($state));
        if (strlen($s) === 2) {
            return $s;
        }

        static $map = [
            'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR', 'CALIFORNIA' => 'CA',
            'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE', 'FLORIDA' => 'FL', 'GEORGIA' => 'GA',
            'HAWAII' => 'HI', 'IDAHO' => 'ID', 'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA',
            'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA', 'MAINE' => 'ME', 'MARYLAND' => 'MD',
            'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN', 'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO',
            'MONTANA' => 'MT', 'NEBRASKA' => 'NE', 'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ',
            'NEW MEXICO' => 'NM', 'NEW YORK' => 'NY', 'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH',
            'OKLAHOMA' => 'OK', 'OREGON' => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC',
            'SOUTH DAKOTA' => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT', 'VERMONT' => 'VT',
            'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA', 'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
            'DISTRICT OF COLUMBIA' => 'DC',
        ];

        return $map[$s] ?? $s;
    }

    private function select(int $perCarrier, int $units): Collection
    {
        if ($csv = $this->option('from-csv')) {
            $rows = array_map('str_getcsv', file($csv, FILE_SKIP_EMPTY_LINES));
            array_shift($rows); // header

            return $this->hydrate(collect($rows)->pluck(0)->filter()->map(fn ($v) => (int) $v));
        }

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

        return $this->hydrate($ids);
    }

    private function hydrate(Collection $ids): Collection
    {
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
            $this->line("<comment>{$label} (n={$n})</comment> — FULL = reproduced the invoice correction; ZIP = matched zip5 only");
            $stats = [];
            foreach (['smarty', 'fedex', 'ups'] as $c) {
                $full = count(array_filter($set, fn ($r) => $r[$c.'_match'] === 'FULL'));
                $zip = count(array_filter($set, fn ($r) => $r[$c.'_match'] === 'ZIP'));
                $err = count(array_filter($set, fn ($r) => $r[$c.'_match'] === 'ERR'));
                $stats[] = [strtoupper($c), $full.' ('.round($full / $n * 100).'%)', $zip, $err];
            }
            $this->table(['API', 'FULL match', 'ZIP-only', 'ERR'], $stats);
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
