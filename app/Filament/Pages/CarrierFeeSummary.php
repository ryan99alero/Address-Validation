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

class CarrierFeeSummary extends Page implements HasTable, ReportSnapshotProvider
{
    use HasReportSnapshots;
    use InteractsWithTable;

    public static function reportKey(): string
    {
        return 'carrier_fee_summary';
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFilters(): array
    {
        return ['carrier_id' => null, 'year_from' => null, 'year_to' => null, 'scope' => 'fees', 'basis' => 'nominal'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentFilters(): array
    {
        $year = $this->getTableFilterState('year') ?? [];

        return [
            'carrier_id' => $this->getTableFilterState('carrier_id')['value'] ?? null,
            'year_from' => $year['year_from'] ?? null,
            'year_to' => $year['year_to'] ?? null,
            'scope' => $this->getTableFilterState('scope')['value'] ?? 'fees',
            'basis' => $this->getTableFilterState('basis')['value'] ?? 'nominal',
        ];
    }

    protected string $view = 'filament.pages.carrier-fee-summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Carrier Fee Summary';

    protected static ?string $title = 'Carrier Fee Summary';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->reportRecords(fn (array $filters): Collection => static::computeData($filters)))
            ->columns([
                TextColumn::make('category')
                    ->label('Fee Category')
                    ->weight('bold'),
                TextColumn::make('carrier')
                    ->label('Carrier')
                    ->badge(),
                TextColumn::make('times')
                    ->label('Times Charged')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('USD')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('avg')
                    ->label('Avg / charge')
                    ->money('USD')
                    ->alignEnd(),
                TextColumn::make('share')
                    ->label('% of fees')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->options(Carrier::pluck('name', 'id')),
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
                SelectFilter::make('scope')
                    ->label('Scope')
                    ->options([
                        'fees' => 'Aux fees only (exclude base transport)',
                        'all' => 'All charges',
                    ])
                    ->default('fees')
                    ->selectablePlaceholder(false),
                SelectFilter::make('basis')
                    ->label('Dollars')
                    ->options([
                        'nominal' => 'Nominal (as billed)',
                        'real' => 'Real (constant 2026 $)',
                    ])
                    ->default('nominal')
                    ->selectablePlaceholder(false),
            ], layout: FiltersLayout::AboveContent)
            ->paginated(false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function computeData(array $filters): Collection
    {
        $carrierId = $filters['carrier_id'] ?? null;
        $from = $filters['year_from'] ?? null;
        $to = $filters['year_to'] ?? null;
        $scope = $filters['scope'] ?? 'fees';
        $real = ($filters['basis'] ?? 'nominal') === 'real';
        $amount = $real ? 'cc.amount * '.InflationIndex::sqlFactor('cc.invoice_date') : 'cc.amount';

        $query = DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as cat', 'cat.id', '=', 'cc.charge_category_id')
            ->leftJoin('carriers as car', 'car.id', '=', 'cc.carrier_id')
            ->selectRaw("COALESCE(cat.name, \"(Uncategorized)\") as category, car.name as carrier, COUNT(*) as times, SUM({$amount}) as total")
            ->groupBy('category', 'carrier');

        if ($carrierId) {
            $query->where('cc.carrier_id', $carrierId);
        }
        if ($from) {
            $query->whereDate('cc.invoice_date', '>=', "{$from}-01-01");
        }
        if ($to) {
            $query->whereDate('cc.invoice_date', '<=', "{$to}-12-31");
        }
        if ($scope === 'fees') {
            $query->where(function ($q): void {
                $q->whereNull('cat.name')->orWhere('cat.name', '!=', 'Base Transportation');
            });
        }

        $rows = $query->orderByDesc('total')->get();
        $grandTotal = (float) $rows->sum('total') ?: 1.0;

        return $rows->values()->map(fn ($r, $i): array => [
            'id' => $i,
            'category' => $r->category,
            'carrier' => $r->carrier ?? '—',
            'times' => (int) $r->times,
            'total' => (float) $r->total,
            'avg' => $r->times ? (float) $r->total / (int) $r->times : 0,
            'share' => (float) $r->total / $grandTotal * 100,
        ]);
    }

    /**
     * In-page legend so anyone opening the report understands each column.
     *
     * @return array{intro: string, metrics: array<int, mixed>, columns: array<int, array{name: string, means: string}>, controls: array<int, array{name: string, means: string}>}
     */
    public function viewGuide(): array
    {
        return [
            'intro' => 'Breaks carrier fees down by category (for one carrier or both), with totals. Categories are normalized across carriers, so the same kind of fee lines up regardless of what UPS vs FedEx calls it.',
            'rule' => 'use Scope "Aux fees only" to focus on surcharges (it drops Base Transportation). Switch "Dollars" to Real when comparing totals across different years, so inflation does not distort the trend. To compare UPS vs FedEx head-to-head on a fee, use the Carrier Comparison report.',
            'metrics' => [],
            'columns' => [
                ['name' => 'Fee Category', 'means' => 'The normalized fee type — e.g. UPS "ADC" and FedEx "ADDCOR" both roll up to Address Correction.'],
                ['name' => 'Carrier', 'means' => 'UPS or FedEx (each category is shown per carrier).'],
                ['name' => 'Times Charged', 'means' => 'How many individual charge lines fall in that category (count of charge rows).'],
                ['name' => 'Total', 'means' => 'Sum of the dollar amounts — nominal, or CPI-adjusted when "Real" is selected.'],
                ['name' => 'Avg / charge', 'means' => 'Average size of each charge = Total ÷ Times Charged.'],
                ['name' => '% of fees', 'means' => 'That row\'s share of the filtered total = row Total ÷ sum of all rows × 100.'],
            ],
            'controls' => [
                ['name' => 'Carrier', 'means' => 'Limit to one carrier, or leave blank for both.'],
                ['name' => 'Scope', 'means' => '"Aux fees only" excludes Base Transportation; "All charges" includes it.'],
                ['name' => 'Year from / to', 'means' => 'Blank both = all years; set one = single year; set both = a range.'],
                ['name' => 'Dollars: Nominal vs Real', 'means' => 'Real restates older years into constant 2026 dollars via CPI, so cross-year totals are not skewed by inflation.'],
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
