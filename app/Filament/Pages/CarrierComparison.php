<?php

namespace App\Filament\Pages;

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

class CarrierComparison extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.carrier-comparison';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Carrier Comparison';

    protected static ?string $title = 'Carrier Comparison — Who Costs More';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->compare())
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
     * @return Collection<int, array<string, mixed>>
     */
    protected function compare(): Collection
    {
        $metric = $this->getTableFilterState('metric')['value'] ?? 'per_ship';
        $yearState = $this->getTableFilterState('year') ?? [];
        $from = $yearState['year_from'] ?? null;
        $to = $yearState['year_to'] ?? null;

        // Optionally restate dollars in constant base-year terms.
        $real = ($this->getTableFilterState('basis')['value'] ?? 'nominal') === 'real';
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
