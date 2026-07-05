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

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Correction Hotspots';

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

        // The real correction fee lives in carrier_charges (category "Address Correction"),
        // NOT on the invoice line (line.charge_amount is 0 for PDF/FedEx-sourced corrections).
        // Join the per-(carrier, tracking) address-correction fee so the numbers are honest.
        $adcCategoryId = DB::table('charge_categories')->where('name', 'Address Correction')->value('id');
        $feeSub = DB::table('carrier_charges')
            ->select('carrier_id', 'tracking_number', DB::raw('SUM(amount) AS fee'))
            ->where('charge_category_id', $adcCategoryId)
            ->whereNotNull('tracking_number')
            ->groupBy('carrier_id', 'tracking_number');

        $rows = DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as ci', 'ci.id', '=', 'l.carrier_invoice_id')
            ->join('carriers as c', 'c.id', '=', 'ci.carrier_id')
            ->leftJoinSub($feeSub, 'f', fn ($join) => $join
                ->on('f.carrier_id', '=', 'ci.carrier_id')
                ->on('f.tracking_number', '=', 'l.tracking_number'))
            ->whereNotNull('l.original_address_1')->where('l.original_address_1', '<>', '')
            ->when($carrierId, fn ($q) => $q->where('ci.carrier_id', $carrierId))
            ->selectRaw('
                l.original_postal AS zip,
                UPPER(SUBSTRING(TRIM(l.original_address_1), 1, 16)) AS cluster,
                MAX(l.original_city) AS city,
                MAX(l.original_state) AS state,
                COUNT(*) AS corrections,
                ROUND(SUM(COALESCE(f.fee, l.charge_amount)), 2) AS fees,
                GROUP_CONCAT(DISTINCT c.slug) AS carriers,
                GROUP_CONCAT(l.change_type) AS change_types
            ')
            ->groupBy('zip', 'cluster')
            ->havingRaw('COUNT(*) >= ?', [$min])
            ->when($address !== '', fn ($q) => $q->havingRaw(
                'SUM(CASE WHEN l.original_address_1 LIKE ? OR l.original_city LIKE ? OR l.original_state LIKE ? OR l.original_postal LIKE ? THEN 1 ELSE 0 END) > 0',
                ["%{$address}%", "%{$address}%", "%{$address}%", "%{$address}%"]
            ))
            ->when($tracking !== '', fn ($q) => $q->havingRaw(
                'SUM(CASE WHEN l.tracking_number LIKE ? THEN 1 ELSE 0 END) > 0',
                ["%{$tracking}%"]
            ))
            ->orderByDesc('fees')
            ->limit($limit)
            ->get();

        return $rows->values()->map(fn ($r, int $i): array => [
            'id' => $i,
            'location' => $r->cluster,
            'city_state_zip' => trim((string) ($r->city ?? '').', '.($r->state ?? '').' '.($r->zip ?? '')),
            'carriers' => strtoupper((string) $r->carriers),
            'corrections' => (int) $r->corrections,
            'fees' => (float) $r->fees,
            'avg_fee' => $r->corrections ? (float) $r->fees / (int) $r->corrections : 0.0,
            'main_issue' => self::dominant((string) $r->change_types),
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
