<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasRebuildReportsAction;
use App\Models\Carrier;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class CorrectionHotspots extends Page implements HasTable
{
    use HasRebuildReportsAction;
    use InteractsWithTable;

    /**
     * @return array<string, mixed>
     */
    protected function currentFilters(): array
    {
        return [
            'carrier_id' => $this->getTableFilterState('carrier_id')['value'] ?? null,
            'min' => (int) ($this->getTableFilterState('min')['value'] ?? 5),
            'address' => $this->getTableFilterState('address')['value'] ?? null,
            'tracking' => $this->getTableFilterState('tracking')['value'] ?? null,
        ];
    }

    protected string $view = 'filament.pages.correction-hotspots';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Costs';

    protected static ?string $navigationLabel = 'Correction Hotspots';

    protected static ?int $navigationSort = 13;

    protected static ?string $title = 'Address Correction Hotspots';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $sortColumn, ?string $sortDirection): Collection {
                $records = static::computeData($this->currentFilters());

                // computeData returns the top hotspots by fees; honour the clicked
                // column header by re-sorting the collection (Filament paginates it).
                if ($sortColumn !== null) {
                    $records = ($sortDirection === 'desc'
                        ? $records->sortByDesc($sortColumn)
                        : $records->sortBy($sortColumn))->values();
                }

                return $records;
            })
            ->columns([
                TextColumn::make('location')->label('Street Cluster')->weight('bold')->wrap(),
                TextColumn::make('city_state_zip')->label('City / State / Zip'),
                TextColumn::make('carriers')->label('Carrier(s)')->badge(),
                TextColumn::make('corrections')->label('Corrections')->numeric()->alignEnd()->sortable(),
                TextColumn::make('fees')->label('Total Fees')->money('USD')->alignEnd()->sortable(),
                TextColumn::make('avg_fee')->label('Avg Fee')->money('USD')->alignEnd(),
                TextColumn::make('main_issue')->label('Main Issue')->badge()->color('warning'),
            ])
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->options(Carrier::whereIn('slug', ['ups', 'fedex'])->pluck('name', 'id')),
                SelectFilter::make('min')
                    ->label('Min corrections')
                    ->options([3 => '3+', 5 => '5+', 10 => '10+', 25 => '25+'])
                    ->default(5)
                    ->selectablePlaceholder(false),
                Filter::make('address')
                    ->schema([
                        TextInput::make('value')
                            ->label('Address contains')
                            ->placeholder('street, city, state, or zip'),
                    ])
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? 'Address: '.$data['value'] : null),
                Filter::make('tracking')
                    ->schema([
                        TextInput::make('value')
                            ->label('Tracking / Shipment #')
                            ->placeholder('tracking number'),
                    ])
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? 'Tracking: '.$data['value'] : null),
            ], layout: FiltersLayout::AboveContent)
            ->paginated([25, 50, 100]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function computeData(array $filters): Collection
    {
        $carrierId = $filters['carrier_id'] ?? null;
        $address = trim((string) ($filters['address'] ?? ''));
        $tracking = trim((string) ($filters['tracking'] ?? ''));
        $searching = $address !== '' || $tracking !== '';

        // A targeted search should surface the matching hotspot regardless of size
        // or fee rank, so relax the min + the fee-ranked cap while searching.
        $min = $searching ? 1 : (int) ($filters['min'] ?? 5);
        $limit = $searching ? 1000 : 300;

        // Cache keyed by a data-version stamp (row count + latest id of the corrections table):
        // when new corrections are imported the stamp changes and the result recomputes; otherwise
        // repeat loads, re-sorts and pagination are served from cache. The recompute itself is two
        // small indexed queries merged in PHP (~0.2s) — the previous derived-table join ran ~87s
        // because MySQL re-scanned the fee subquery once per correction line.
        $version = DB::table('carrier_invoice_lines')->count().':'.(DB::table('carrier_invoice_lines')->max('id') ?? 0);
        $cacheKey = 'correction-hotspots:'.md5($version.'|'.json_encode([$carrierId, $min, $limit, $address, $tracking]));

        return Cache::remember($cacheKey, now()->addHours(6), fn (): Collection => self::buildHotspots($carrierId, $address, $tracking, $min, $limit));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected static function buildHotspots(?int $carrierId, string $address, string $tracking, int $min, int $limit): Collection
    {
        // The real correction fee lives in carrier_charges (category "Address Correction"), NOT on
        // the invoice line (line.charge_amount is 0 for PDF/FedEx-sourced corrections). Pull the
        // per-(carrier, tracking) fee once into a map and look it up per line in PHP — the SQL join
        // between this and the line aggregation is what made the page unusable.
        $adcCategoryId = DB::table('charge_categories')->where('name', 'Address Correction')->value('id');
        $feeMap = DB::table('carrier_charges')
            ->select('carrier_id', 'tracking_number', DB::raw('SUM(amount) AS fee'))
            ->where('charge_category_id', $adcCategoryId)
            ->whereNotNull('tracking_number')
            ->groupBy('carrier_id', 'tracking_number')
            ->get()
            ->mapWithKeys(fn ($r): array => [$r->carrier_id.'|'.$r->tracking_number => (float) $r->fee]);

        $lines = DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as ci', 'ci.id', '=', 'l.carrier_invoice_id')
            ->join('carriers as c', 'c.id', '=', 'ci.carrier_id')
            ->whereNotNull('l.original_address_1')->where('l.original_address_1', '<>', '')
            ->when($carrierId, fn ($q) => $q->where('ci.carrier_id', $carrierId))
            ->get(['ci.carrier_id', 'c.slug as carrier_slug', 'l.tracking_number', 'l.original_postal as zip',
                'l.original_address_1', 'l.original_city', 'l.original_state', 'l.change_type', 'l.charge_amount']);

        // Aggregate by (zip, 16-char street cluster) in PHP.
        $groups = [];
        foreach ($lines as $l) {
            $cluster = strtoupper(substr(trim((string) $l->original_address_1), 0, 16));
            $key = ((string) $l->zip).'|'.$cluster;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'zip' => $l->zip, 'cluster' => $cluster,
                    'city' => (string) ($l->original_city ?? ''), 'state' => (string) ($l->original_state ?? ''),
                    'corrections' => 0, 'fees' => 0.0, 'carriers' => [], 'change_types' => [],
                    'match_addr' => false, 'match_track' => false,
                ];
            }
            $groups[$key]['corrections']++;
            $groups[$key]['fees'] += $feeMap[$l->carrier_id.'|'.$l->tracking_number] ?? (float) $l->charge_amount;
            if ($l->carrier_slug) {
                $groups[$key]['carriers'][$l->carrier_slug] = true;
            }
            if ($l->change_type) {
                $groups[$key]['change_types'][] = $l->change_type;
            }
            if ($address !== '' && ! $groups[$key]['match_addr']) {
                $hay = strtolower(($l->original_address_1 ?? '').' '.($l->original_city ?? '').' '.($l->original_state ?? '').' '.($l->zip ?? ''));
                if (str_contains($hay, strtolower($address))) {
                    $groups[$key]['match_addr'] = true;
                }
            }
            if ($tracking !== '' && ! $groups[$key]['match_track'] && $l->tracking_number !== null && stripos((string) $l->tracking_number, $tracking) !== false) {
                $groups[$key]['match_track'] = true;
            }
        }

        return collect($groups)
            ->filter(fn (array $g): bool => $g['corrections'] >= $min
                && ($address === '' || $g['match_addr'])
                && ($tracking === '' || $g['match_track']))
            ->sortByDesc('fees')
            ->take($limit)
            ->values()
            ->map(fn (array $g, int $i): array => [
                'id' => $i,
                'location' => $g['cluster'],
                'city_state_zip' => trim($g['city'].', '.$g['state'].' '.((string) ($g['zip'] ?? ''))),
                'carriers' => strtoupper(implode(',', array_keys($g['carriers']))),
                'corrections' => (int) $g['corrections'],
                'fees' => round((float) $g['fees'], 2),
                'avg_fee' => $g['corrections'] ? (float) $g['fees'] / (int) $g['corrections'] : 0.0,
                'main_issue' => self::dominant(implode(',', $g['change_types'])),
            ]);
    }

    /**
     * Most frequent change_type from a comma-separated list (GROUP_CONCAT).
     */
    protected static function dominant(string $list): string
    {
        if ($list === '') {
            return '—';
        }

        $counts = array_count_values(array_filter(explode(',', $list)));
        if ($counts === []) {
            return '—';
        }

        arsort($counts);

        return ucwords(str_replace('_', ' ', (string) array_key_first($counts)));
    }
}
