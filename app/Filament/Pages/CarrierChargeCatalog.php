<?php

namespace App\Filament\Pages;

use App\Enums\ChargeDriver;
use App\Models\Carrier;
use App\Models\CarrierCharge;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every distinct charge the carriers have billed us (raw code + description), shown next to the
 * canonical category and driver we normalize it to — the "100% of charges" audit view. Backed by a
 * grouped query so Filament paginates at the DB level (there are thousands of distinct charge types
 * — loading them all into a collection exhausts memory). A null category flags a mapping gap.
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

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CarrierCharge::query()
                    ->leftJoin('carriers as ca', 'ca.id', '=', 'carrier_charges.carrier_id')
                    ->leftJoin('charge_categories as c', 'c.id', '=', 'carrier_charges.charge_category_id')
                    ->groupBy('carrier_charges.carrier_id', 'ca.name', 'carrier_charges.raw_charge_code', 'carrier_charges.raw_charge_description', 'carrier_charges.charge_category_id', 'c.abbreviation', 'carrier_charges.driver')
                    ->selectRaw('MIN(carrier_charges.id) AS id, ca.name AS carrier, carrier_charges.raw_charge_code AS code,
                        carrier_charges.raw_charge_description AS description, c.abbreviation AS category, carrier_charges.driver AS driver,
                        COUNT(*) AS line_count, ROUND(SUM(carrier_charges.amount), 2) AS total')
            )
            ->columns([
                TextColumn::make('carrier')->badge()->sortable()
                    ->color(fn (?string $state): string => match ($state) {
                        'FedEx' => 'purple', 'UPS' => 'warning', default => 'gray',
                    }),
                TextColumn::make('code')->label('Raw Code')->fontFamily('mono')->placeholder('—')
                    ->searchable(query: fn (Builder $q, string $s): Builder => $q->where('carrier_charges.raw_charge_code', 'like', "%{$s}%")),
                TextColumn::make('description')->label('Carrier Description')->wrap()->limit(48)
                    ->searchable(query: fn (Builder $q, string $s): Builder => $q->where('carrier_charges.raw_charge_description', 'like', "%{$s}%")),
                TextColumn::make('category')->label('→ Category')->badge()
                    ->getStateUsing(fn (CarrierCharge $record): string => $record->category ?? 'UNMAPPED')
                    ->color(fn (CarrierCharge $record): string => $record->category !== null ? 'gray' : 'danger'),
                TextColumn::make('driver')->label('→ Driver')->badge()->color('info')
                    ->getStateUsing(fn (CarrierCharge $record): string => $record->driver !== null ? (ChargeDriver::tryFrom($record->driver)?->abbreviation() ?? $record->driver) : '—'),
                TextColumn::make('line_count')->label('Lines')->numeric()->sortable()->alignEnd(),
                TextColumn::make('total')->label('Total Billed')->money('USD')->sortable()->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->attribute('carrier_charges.carrier_id'),
            ])
            ->defaultSort('total', 'desc')
            ->paginated([50, 100])
            ->emptyStateHeading('No charges imported yet');
    }
}
