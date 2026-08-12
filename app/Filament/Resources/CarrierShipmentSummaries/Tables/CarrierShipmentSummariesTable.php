<?php

namespace App\Filament\Resources\CarrierShipmentSummaries\Tables;

use App\Models\CarrierShipment;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CarrierShipmentSummariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('ship_date', 'desc')
            ->columns([
                TextColumn::make('ship_date')
                    ->label('Ship Date')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->weight('bold')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->sortable(),
                TextColumn::make('service')
                    ->label('Service')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('base_amount')
                    ->label('Base / Initial')
                    ->money('USD')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('fee_amount')
                    ->label('Fees')
                    ->money('USD')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('fee_abbrevs')
                    ->label('Fees Applied')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('printed_total')
                    ->label('Total')
                    ->money('USD')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->summarize(Sum::make()->money('USD')->label('Total')),
            ])
            ->filtersFormColumns(3)
            ->filters([
                // Free-text filters — all live in the one filter panel alongside the dropdowns.
                self::textFilter('invoice_number', 'Invoice #', fn (Builder $q, string $v): Builder => $q->whereHas('invoice', fn (Builder $iq): Builder => $iq->where('invoice_number', 'like', "%{$v}%"))),
                self::textFilter('tracking', 'Tracking #', fn (Builder $q, string $v): Builder => $q->where('tracking_number', 'like', "%{$v}%")),
                self::textFilter('job', 'Job #', fn (Builder $q, string $v): Builder => self::whereTrackingMatch($q, 'carton_costs', 'pace_job_number', $v)),
                self::textFilter('customer', 'Customer ID', fn (Builder $q, string $v): Builder => self::whereTrackingMatch($q, 'carton_costs', 'pace_customer_id', $v)),
                self::textFilter('address', 'Address', fn (Builder $q, string $v): Builder => self::whereTrackingMatch($q, 'carrier_invoice_lines', 'original_address_1', $v)),
                self::textFilter('city', 'City', fn (Builder $q, string $v): Builder => self::whereTrackingMatch($q, 'carrier_invoice_lines', 'original_city', $v)),
                self::textFilter('state', 'State', fn (Builder $q, string $v): Builder => self::whereTrackingMatch($q, 'carrier_invoice_lines', 'original_state', $v)),
                self::textFilter('zip', 'Zip', fn (Builder $q, string $v): Builder => $q->where('zip', 'like', "{$v}%")),
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->relationship('carrier', 'name'),
                SelectFilter::make('service')
                    ->label('Service')
                    ->options(fn (): array => CarrierShipment::query()
                        ->whereNotNull('service')
                        ->distinct()
                        ->orderBy('service')
                        ->pluck('service', 'service')
                        ->all()),
                SelectFilter::make('is_third_party')
                    ->label('Billing')
                    ->options([1 => '3rd Party', 0 => 'On Account']),
                Filter::make('ship_date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('ship_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('ship_date', '<=', $d))),
                Filter::make('has_fees')
                    ->label('Has extra fees')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('fee_amount', '>', 0)),
            ], layout: FiltersLayout::Modal)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * A single-text-input filter that applies $apply(query, value) when filled, with a chip indicator.
     *
     * @param  callable(Builder, string): Builder  $apply
     */
    private static function textFilter(string $name, string $label, callable $apply): Filter
    {
        return Filter::make($name)
            ->schema([TextInput::make('value')->label($label)])
            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                ? $apply($query, trim((string) $data['value']))
                : $query)
            ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? $label.': '.$data['value'] : null);
    }

    /**
     * Constrain shipments to those whose tracking number matches a LIKE on $column of a related table
     * (carton_costs for job/customer, carrier_invoice_lines for the shipped-to address). Correlated
     * EXISTS on the indexed tracking_number, so it only touches matching rows.
     */
    private static function whereTrackingMatch(Builder $query, string $table, string $column, string $value): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->from($table)
            ->whereColumn("{$table}.tracking_number", 'carrier_shipments.tracking_number')
            ->where("{$table}.{$column}", 'like', '%'.$value.'%'));
    }
}
