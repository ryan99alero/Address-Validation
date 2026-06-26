<?php

namespace App\Filament\Pages;

use App\Models\Carrier;
use App\Models\CarrierChargeRollup;
use App\Models\CarrierShipRollup;
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

class CarrierCorrectionAudit extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * @return array<string, mixed>
     */
    protected function currentFilters(): array
    {
        $year = $this->getTableFilterState('year') ?? [];

        return [
            'segment' => $this->getTableFilterState('segment')['value'] ?? 'severity_category',
            'year_from' => $year['year_from'] ?? null,
            'year_to' => $year['year_to'] ?? null,
        ];
    }

    protected string $view = 'filament.pages.carrier-correction-audit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Carrier Correction Audit';

    protected static ?string $title = 'Carrier Correction Audit';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => static::computeData($this->currentFilters()))
            ->columns([
                TextColumn::make('segment')->label('Correction Type')->weight('bold'),
                TextColumn::make('ups_count')->label('UPS #')->numeric()->alignEnd()->color('gray'),
                TextColumn::make('ups_rate')->label('UPS / 1,000')->alignEnd()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),
                TextColumn::make('fedex_count')->label('FedEx #')->numeric()->alignEnd()->color('gray'),
                TextColumn::make('fedex_rate')->label('FedEx / 1,000')->alignEnd()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),
                TextColumn::make('aggressor')->label('More Aggressive')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'UPS' => 'warning',
                        'FedEx' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('gap')->label('Gap')->alignEnd()
                    ->formatStateUsing(fn ($state): string => $state ? number_format((float) $state, 1).'×' : '—'),
            ])
            ->filters([
                SelectFilter::make('segment')
                    ->label('Segment by')
                    ->options([
                        'severity_category' => 'Severity',
                        'change_type' => 'What changed',
                    ])
                    ->default('severity_category')
                    ->selectablePlaceholder(false),
                Filter::make('year')
                    ->schema([
                        Select::make('year_from')->label('Year from')->options($this->yearOptions())->placeholder('Earliest'),
                        Select::make('year_to')->label('Year to')->options($this->yearOptions())->placeholder('Latest'),
                    ])
                    ->columns(2),
            ], layout: FiltersLayout::AboveContent)
            ->paginated(false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function computeData(array $filters): Collection
    {
        $segment = $filters['segment'] ?? 'severity_category';
        $column = $segment === 'change_type' ? 'change_type' : 'severity_category';
        $from = $filters['year_from'] ?? null;
        $to = $filters['year_to'] ?? null;

        $carriers = Carrier::whereIn('slug', ['ups', 'fedex'])->pluck('slug', 'id');
        $upsId = $carriers->search('ups');
        $fedexId = $carriers->search('fedex');

        // Denominator: shipments per carrier, summed from the ship rollup.
        $ships = CarrierShipRollup::query()
            ->when($from, fn ($q) => $q->where('year', '>=', $from))
            ->when($to, fn ($q) => $q->where('year', '<=', $to))
            ->get()
            ->groupBy('carrier_id')
            ->map(fn ($rows): int => (int) $rows->sum('total_ships'));

        $upsShips = max(1, (int) ($ships[$upsId] ?? 0));
        $fedexShips = max(1, (int) ($ships[$fedexId] ?? 0));

        // RELIABLE total: Address Correction *charges* per carrier (from the rollup;
        // captured for both carriers, unlike address-detail lines which FedEx CSV
        // imports don't produce).
        $totalCorrections = CarrierChargeRollup::query()
            ->join('charge_categories as cat', 'cat.id', '=', 'carrier_charge_rollup.charge_category_id')
            ->where('cat.name', 'Address Correction')
            ->when($from, fn ($q) => $q->where('year', '>=', $from))
            ->when($to, fn ($q) => $q->where('year', '<=', $to))
            ->get(['carrier_id', 'charge_count'])
            ->groupBy('carrier_id')
            ->map(fn ($rows): int => (int) $rows->sum('charge_count'));

        // Severity/change segmentation: only available where we captured the
        // address detail (a graded line). Complete for UPS, partial for FedEx.
        $counts = DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as ci', 'ci.id', '=', 'l.carrier_invoice_id')
            ->whereNotNull("l.{$column}")
            ->when($from, fn ($q) => $q->whereYear('l.ship_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereYear('l.ship_date', '<=', $to))
            ->selectRaw("ci.carrier_id, l.{$column} AS segment, COUNT(*) AS n")
            ->groupBy('ci.carrier_id', 'segment')
            ->get();

        $rate = fn (int $n, int $ships): float => $n / $ships * 1000;

        // Headline row: all corrections (charge-based, reliable).
        $upsTotal = (int) ($totalCorrections[$upsId] ?? 0);
        $fedexTotal = (int) ($totalCorrections[$fedexId] ?? 0);
        $upsTotalRate = $rate($upsTotal, $upsShips);
        $fedexTotalRate = $rate($fedexTotal, $fedexShips);
        $loT = min($upsTotalRate, $fedexTotalRate);
        $hiT = max($upsTotalRate, $fedexTotalRate);
        $overall = [
            'id' => -1,
            'segment' => '▸ All Corrections (per shipment)',
            'ups_count' => $upsTotal,
            'ups_rate' => $upsTotalRate,
            'fedex_count' => $fedexTotal,
            'fedex_rate' => $fedexTotalRate,
            'aggressor' => $upsTotalRate === $fedexTotalRate ? null : ($upsTotalRate > $fedexTotalRate ? 'UPS' : 'FedEx'),
            'gap' => $loT > 0 ? $hiT / $loT : null,
        ];

        $segments = $counts->pluck('segment')->unique()->filter()->values();

        $segmentRows = $segments->map(function (string $value, int $i) use ($counts, $upsId, $fedexId, $upsShips, $fedexShips): array {
            $upsN = (int) (optional($counts->first(fn ($r) => (int) $r->carrier_id === (int) $upsId && $r->segment === $value))->n ?? 0);
            $fedexN = (int) (optional($counts->first(fn ($r) => (int) $r->carrier_id === (int) $fedexId && $r->segment === $value))->n ?? 0);

            $upsRate = $upsN / $upsShips * 1000;
            $fedexRate = $fedexN / $fedexShips * 1000;
            $lo = min($upsRate, $fedexRate);
            $hi = max($upsRate, $fedexRate);

            return [
                'id' => $i,
                'segment' => '  '.ucwords(str_replace('_', ' ', $value)).' (graded)',
                'ups_count' => $upsN,
                'ups_rate' => $upsRate,
                'fedex_count' => $fedexN,
                'fedex_rate' => $fedexRate,
                'aggressor' => $upsRate === $fedexRate ? null : ($upsRate > $fedexRate ? 'UPS' : 'FedEx'),
                'gap' => $lo > 0 ? $hi / $lo : null,
            ];
        })->sortByDesc(fn (array $r): float => max($r['ups_rate'], $r['fedex_rate']))->values();

        return collect([$overall])->concat($segmentRows)->values();
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
}
