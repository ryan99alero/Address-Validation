<?php

namespace App\Filament\Pages;

use App\Enums\ChargeDriver;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Every distinct charge the carriers have billed us (raw code + description), shown next to the
 * canonical category and driver we normalize it to — the "100% of charges" audit view. One row per
 * distinct (carrier, raw code, description, category, driver); a null category flags a mapping gap.
 */
class CarrierChargeCatalog extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.carrier-charge-catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Invoices';

    protected static ?string $navigationLabel = 'Charge Catalog';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Carrier Charge Catalog';

    /**
     * Distinct raw charges → canonical mapping, aggregated with volume. Cached briefly: it groups
     * the full charges table and only changes when invoices import.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rows(): Collection
    {
        return Cache::remember('charge_catalog.rows', now()->addMinutes(10), fn (): Collection => DB::table('carrier_charges as cc')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'cc.carrier_id')
            ->leftJoin('charge_categories as c', 'c.id', '=', 'cc.charge_category_id')
            ->groupBy('ca.name', 'cc.raw_charge_code', 'cc.raw_charge_description', 'c.abbreviation', 'cc.driver')
            ->selectRaw('ca.name AS carrier, cc.raw_charge_code AS code, cc.raw_charge_description AS description,
                c.abbreviation AS category, cc.driver AS driver, COUNT(*) AS lines, ROUND(SUM(cc.amount), 2) AS total')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $r, int $i): array => [
                'id' => $i,
                'carrier' => $r->carrier ?? '—',
                'code' => $r->code,
                'description' => $r->description,
                'category' => $r->category ?? 'UNMAPPED',
                'mapped' => $r->category !== null,
                'driver' => $r->driver !== null ? (ChargeDriver::tryFrom($r->driver)?->abbreviation() ?? $r->driver) : '—',
                'lines' => (int) $r->lines,
                'total' => (float) $r->total,
            ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => static::rows())
            ->columns([
                TextColumn::make('carrier')->label('Carrier')->badge()->sortable()->searchable()
                    ->color(fn (string $state): string => match ($state) {
                        'FedEx' => 'purple', 'UPS' => 'warning', default => 'gray',
                    }),
                TextColumn::make('code')->label('Raw Code')->fontFamily('mono')->searchable()->placeholder('—'),
                TextColumn::make('description')->label('Carrier Description')->searchable()->wrap()->limit(48),
                TextColumn::make('category')->label('→ Category')->badge()->searchable()
                    ->color(fn (array $record): string => $record['mapped'] ? 'gray' : 'danger'),
                TextColumn::make('driver')->label('→ Driver')->badge()->searchable()->color('info'),
                TextColumn::make('lines')->label('Lines')->numeric()->sortable()->alignEnd(),
                TextColumn::make('total')->label('Total Billed')->money('USD')->sortable()->alignEnd(),
            ])
            ->defaultSort('total', 'desc')
            ->paginated([50, 100, 'all'])
            ->emptyStateHeading('No charges imported yet');
    }
}
