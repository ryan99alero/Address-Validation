<?php

namespace App\Filament\Pages;

use App\Contracts\ReportSnapshotProvider;
use App\Filament\Pages\Concerns\HasReportSnapshots;
use App\Models\Carrier;
use App\Services\InflationIndex;
use BackedEnum;
use Filament\Forms\Components\Select;
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

class CarrierComparison extends Page implements HasTable, ReportSnapshotProvider
{
    use HasReportSnapshots;
    use InteractsWithTable;

    public static function reportKey(): string
    {
        return 'carrier_comparison';
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFilters(): array
    {
        return ['metric' => 'avg', 'basis' => 'nominal', 'year_from' => null, 'year_to' => null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentFilters(): array
    {
        $year = $this->getTableFilterState('year') ?? [];

        return [
            'metric' => $this->getTableFilterState('metric')['value'] ?? 'avg',
            'basis' => $this->getTableFilterState('basis')['value'] ?? 'nominal',
            'year_from' => $year['year_from'] ?? null,
            'year_to' => $year['year_to'] ?? null,
        ];
    }

    protected string $view = 'filament.pages.carrier-comparison';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Carrier Comparison';

    protected static ?string $title = 'Carrier Comparison — Who Costs More';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->reportRecords(fn (array $filters): Collection => static::computeData($filters)))
            ->columns([
                TextColumn::make('category')->label('Fee Category')->weight('bold'),
                TextColumn::make('ups')->label('UPS')->alignEnd()
                    ->formatStateUsing(fn ($state, array $record): string => $this->fmt($state, $record['metric'])),
                TextColumn::make('fedex')->label('FedEx')->alignEnd()
                    ->formatStateUsing(fn ($state, array $record): string => $this->fmt($state, $record['metric'])),
                TextColumn::make('worse')->label('Costlier')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'UPS' => 'warning',
                        'FedEx' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('gap')->label('Gap')->alignEnd()
                    ->formatStateUsing(fn ($state): string => $state ? number_format((float) $state, 1).'×' : '—')
                    ->tooltip('How many times more the costlier carrier charges for this fee'),
            ])
            ->filters([
                SelectFilter::make('metric')
                    ->label('Compare by')
                    ->options([
                        'avg' => 'Avg $ per charge (rate when applied)',
                        'per_ship' => '$ per shipment',
                        'incidence' => 'Incidence % (shipments hit)',
                        'load' => 'Fee load % (fees ÷ base spend)',
                        'total' => 'Total $',
                    ])
                    ->default('avg')
                    ->selectablePlaceholder(false),
                SelectFilter::make('basis')
                    ->label('Dollars')
                    ->options([
                        'nominal' => 'Nominal (as billed)',
                        'real' => 'Real (constant 2026 $)',
                    ])
                    ->default('nominal')
                    ->selectablePlaceholder(false),
                Filter::make('year')
                    ->schema([
                        Select::make('year_from')
                            ->label('Year from')
                            ->options($this->yearOptions())
                            ->placeholder('Earliest'),
                        Select::make('year_to')
                            ->label('Year to')
                            ->options($this->yearOptions())
                            ->placeholder('Latest'),
                    ])
                    ->columns(2)
                    ->indicateUsing(fn (array $data): ?string => $this->yearIndicator($data)),
            ], layout: FiltersLayout::AboveContent)
            ->paginated(false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function computeData(array $filters): Collection
    {
        $metric = $filters['metric'] ?? 'avg';
        $from = $filters['year_from'] ?? null;
        $to = $filters['year_to'] ?? null;

        // Optionally restate dollars in constant base-year terms.
        $real = ($filters['basis'] ?? 'nominal') === 'real';
        $amount = $real ? 'cc.amount * '.InflationIndex::sqlFactor('cc.invoice_date') : 'cc.amount';

        $carriers = Carrier::whereIn('slug', ['ups', 'fedex'])->pluck('slug', 'id');
        $upsId = $carriers->search('ups');
        $fedexId = $carriers->search('fedex');

        // Per carrier+category: fee dollars + shipments that incurred the fee.
        $byCat = DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as cat', 'cat.id', '=', 'cc.charge_category_id')
            ->selectRaw("cc.carrier_id, COALESCE(cat.name, \"(Uncategorized)\") as category, SUM({$amount}) as amount, COUNT(DISTINCT cc.tracking_number) as ships, COUNT(*) as charge_count")
            ->when($from, fn ($q) => $q->whereDate('cc.invoice_date', '>=', "{$from}-01-01"))
            ->when($to, fn ($q) => $q->whereDate('cc.invoice_date', '<=', "{$to}-12-31"))
            ->groupBy('cc.carrier_id', 'category')
            ->get();

        // Per carrier denominators: total shipments + base-transport spend.
        $totals = DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as cat', 'cat.id', '=', 'cc.charge_category_id')
            ->selectRaw("cc.carrier_id, COUNT(DISTINCT cc.tracking_number) as total_ships, SUM(CASE WHEN cat.name = \"Base Transportation\" THEN {$amount} ELSE 0 END) as base_spend, COUNT(DISTINCT CASE WHEN (cat.name IS NULL OR cat.name NOT IN (\"Base Transportation\", \"Discount / Credit\")) AND cc.amount > 0 THEN cc.tracking_number END) as aux_ships")
            ->when($from, fn ($q) => $q->whereDate('cc.invoice_date', '>=', "{$from}-01-01"))
            ->when($to, fn ($q) => $q->whereDate('cc.invoice_date', '<=', "{$to}-12-31"))
            ->groupBy('cc.carrier_id')
            ->get()
            ->keyBy('carrier_id');

        $value = function (?object $row, ?object $tot) use ($metric): float {
            if (! $row || ! $tot) {
                return 0.0;
            }

            return match ($metric) {
                'total' => (float) $row->amount,
                'avg' => ($row->charge_count ?? 0) ? (float) $row->amount / (float) $row->charge_count : 0.0,
                'per_ship' => $tot->total_ships ? (float) $row->amount / $tot->total_ships : 0.0,
                'incidence' => $tot->total_ships ? (float) $row->ships / $tot->total_ships * 100 : 0.0,
                'load' => $tot->base_spend ? (float) $row->amount / $tot->base_spend * 100 : 0.0,
                default => 0.0,
            };
        };

        // Auxiliary categories only — exclude Base Transportation (the denominator)
        // and Discount / Credit (those are credits, not charges).
        $isAux = fn (string $category): bool => ! in_array($category, ['Base Transportation', 'Discount / Credit'], true);

        $buildRow = function (string $category, ?object $upsRow, ?object $fedexRow) use ($value, $totals, $upsId, $fedexId, $metric): array {
            $ups = $value($upsRow, $totals->get($upsId));
            $fedex = $value($fedexRow, $totals->get($fedexId));
            $lo = min($ups, $fedex);
            $hi = max($ups, $fedex);

            return [
                'metric' => $metric,
                'category' => $category,
                'ups' => $ups,
                'fedex' => $fedex,
                'worse' => $ups === $fedex ? null : ($ups > $fedex ? 'UPS' : 'FedEx'),
                'gap' => $lo > 0 ? $hi / $lo : null,
            ];
        };

        // Overall row: aggregate of all auxiliary categories per carrier.
        $auxAgg = fn ($carrierId): object => (object) [
            'amount' => $byCat->filter(fn ($r) => (int) $r->carrier_id === (int) $carrierId && $isAux($r->category))->sum('amount'),
            'charge_count' => $byCat->filter(fn ($r) => (int) $r->carrier_id === (int) $carrierId && $isAux($r->category))->sum('charge_count'),
            'ships' => $totals->get($carrierId)?->aux_ships ?? 0,
        ];
        $overall = $buildRow('▸ ALL AUXILIARY FEES', $auxAgg($upsId), $auxAgg($fedexId));

        $categoryRows = $byCat->pluck('category')->unique()
            ->reject(fn ($c) => $c === 'Base Transportation')
            ->values()
            ->map(fn (string $category): array => $buildRow(
                $category,
                $byCat->first(fn ($r) => (int) $r->carrier_id === (int) $upsId && $r->category === $category),
                $byCat->first(fn ($r) => (int) $r->carrier_id === (int) $fedexId && $r->category === $category),
            ))
            ->sortByDesc(fn (array $r): float => max($r['ups'], $r['fedex']))
            ->values();

        return collect([$overall])->concat($categoryRows)->values()
            ->map(function (array $row, int $i): array {
                $row['id'] = $i;

                return $row;
            });
    }

    protected function fmt(mixed $state, string $metric): string
    {
        $value = (float) $state;

        return match ($metric) {
            'incidence', 'load' => number_format($value, 1).'%',
            default => '$'.number_format($value, $value < 100 ? 2 : 0),
        };
    }

    /**
     * In-page legend so anyone opening the report understands each metric.
     *
     * @return array{intro: string, metrics: array<int, array{name: string, means: string, formula: string, use: string}>, columns: array<int, array{name: string, means: string}>, controls: array<int, array{name: string, means: string}>}
     */
    public function viewGuide(): array
    {
        return [
            'intro' => 'Compares UPS vs FedEx on auxiliary (surcharge) fees — per fee category and overall. The first four metrics divide out shipment volume, so the carriers having very different shipment counts does NOT distort the comparison.',
            'rule' => 'match the metric to the fee. Rate-based fees (Fuel, Delivery Area, Residential — charged as a % of the shipment cost) → use "Fee load %", the true rate. Flat fees (Address Correction, Additional Handling, Oversize) → use "Avg $ per charge". The wrong lens can flip the answer: by $/charge FedEx fuel looks 2.5× pricier, but by rate UPS actually charges more (14.2% vs 10.2%) — the $/charge gap is just FedEx shipping bigger packages.',
            'metrics' => [
                ['name' => 'Avg $ per charge', 'means' => 'When a fee is applied, the average dollar size of it.', 'formula' => 'fee $ ÷ number of times that fee was charged', 'use' => 'Pure rate — volume-independent. Best for "whose fuel fee is bigger each time it hits?"'],
                ['name' => '$ per shipment', 'means' => 'That fee spread across every shipment the carrier made.', 'formula' => 'fee $ ÷ total shipments (distinct tracking #s)', 'use' => 'Blends the rate with how often it is applied.'],
                ['name' => 'Incidence %', 'means' => 'How often the fee is applied.', 'formula' => 'shipments hit by the fee ÷ total shipments × 100', 'use' => 'Frequency only, not size.'],
                ['name' => 'Fee load %', 'means' => 'The fee as a share of base shipping cost.', 'formula' => 'fee $ ÷ base transportation $ × 100', 'use' => 'Best for cost-based surcharges like fuel (= the effective rate). Already inflation-neutral.'],
                ['name' => 'Total $', 'means' => 'Raw dollars billed.', 'formula' => 'sum of the fee', 'use' => 'Not normalized — driven by volume. Use only for absolute totals.'],
            ],
            'columns' => [
                ['name' => 'UPS / FedEx', 'means' => 'The selected metric, computed for each carrier.'],
                ['name' => 'Costlier', 'means' => 'Which carrier is higher for that fee on the selected metric.'],
                ['name' => 'Gap', 'means' => 'How many times more the costlier carrier charges (higher ÷ lower).'],
                ['name' => '▸ ALL AUXILIARY FEES (top row)', 'means' => 'The selected metric aggregated across every aux fee. Excludes base transportation and discounts/credits (those are not charges).'],
            ],
            'controls' => [
                ['name' => 'Dollars: Nominal vs Real', 'means' => 'Real restates each year\'s dollars into constant 2026 dollars using CPI, so comparing $ across years is not distorted by inflation. Affects the $ metrics only; the % metrics are already inflation-neutral.'],
                ['name' => 'Year from / to', 'means' => 'Blank both = all years; set one = single year; set both = a range.'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function yearOptions(): array
    {
        $years = [];
        for ($y = (int) date('Y'); $y >= 2009; $y--) {
            $years[$y] = (string) $y;
        }

        return $years;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function yearIndicator(array $data): ?string
    {
        $from = $data['year_from'] ?? null;
        $to = $data['year_to'] ?? null;
        if (! $from && ! $to) {
            return null;
        }
        if ($from && $to) {
            return $from === $to ? "Year {$from}" : "Years {$from}–{$to}";
        }

        return $from ? "{$from} onward" : "Through {$to}";
    }
}
