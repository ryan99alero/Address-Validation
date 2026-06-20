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

class CarrierFeeSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.carrier-fee-summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Carrier Fee Summary';

    protected static ?string $title = 'Carrier Fee Summary';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->aggregate())
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
     * @return Collection<int, array<string, mixed>>
     */
    protected function aggregate(): Collection
    {
        $carrierId = $this->getTableFilterState('carrier_id')['value'] ?? null;
        $yearState = $this->getTableFilterState('year') ?? [];
        $scope = $this->getTableFilterState('scope')['value'] ?? 'fees';
        $real = ($this->getTableFilterState('basis')['value'] ?? 'nominal') === 'real';
        $amount = $real ? 'cc.amount * '.InflationIndex::sqlFactor('cc.invoice_date') : 'cc.amount';

        $query = DB::table('carrier_charges as cc')
            ->leftJoin('charge_categories as cat', 'cat.id', '=', 'cc.charge_category_id')
            ->leftJoin('carriers as car', 'car.id', '=', 'cc.carrier_id')
            ->selectRaw("COALESCE(cat.name, \"(Uncategorized)\") as category, car.name as carrier, COUNT(*) as times, SUM({$amount}) as total")
            ->groupBy('category', 'carrier');

        if ($carrierId) {
            $query->where('cc.carrier_id', $carrierId);
        }
        if ($from = $yearState['year_from'] ?? null) {
            $query->whereDate('cc.invoice_date', '>=', "{$from}-01-01");
        }
        if ($to = $yearState['year_to'] ?? null) {
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
