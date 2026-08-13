<?php

namespace App\Filament\Resources\CarrierShipmentSummaries\Tables;

use App\Filament\Support\ShipmentFilters;
use App\Models\CarrierShipment;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice #')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('carton.pace_job_number')
                    ->label('Job #')
                    ->placeholder('— (not invoiced/known in Pace)')
                    ->toggleable(),
                TextColumn::make('carton.pace_customer_id')
                    ->label('Customer')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('carton.pace_customer_name')
                    ->label('Customer Name')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('carton.U_reference')
                    ->label('Reference 1')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('carton.U_reference2')
                    ->label('Reference 2')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('zip')
                    ->label('Zip')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('zone')
                    ->label('Zone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('billed_weight')
                    ->label('Billed Wt')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('section')
                    ->label('Section')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_third_party')
                    ->label('Billing')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? '3rd Party' : 'On Account')
                    ->color(fn ($state): string => $state ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormColumns(3)
            ->filters([
                // Free-text filters — shared with the All Charges view (see ShipmentFilters).
                ShipmentFilters::text('invoice_number', 'Invoice #', fn (Builder $q, string $v): Builder => $q->whereHas('invoice', fn (Builder $iq): Builder => $iq->where('invoice_number', 'like', "%{$v}%"))),
                ShipmentFilters::text('tracking', 'Tracking #', fn (Builder $q, string $v): Builder => $q->where('tracking_number', 'like', "%{$v}%")),
                ShipmentFilters::text('job', 'Job # (exact)', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carton_costs', 'pace_job_number', $v, exact: true)),
                ShipmentFilters::text('customer', 'Customer ID (exact)', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carton_costs', 'pace_customer_id', $v, exact: true)),
                ShipmentFilters::text('reference1', 'Reference 1', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carton_costs', 'U_reference', $v)),
                ShipmentFilters::text('reference2', 'Reference 2', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carton_costs', 'U_reference2', $v)),
                ShipmentFilters::text('address', 'Address', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carrier_invoice_lines', 'original_address_1', $v)),
                ShipmentFilters::text('city', 'City', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carrier_invoice_lines', 'original_city', $v)),
                ShipmentFilters::text('state', 'State', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'carrier_shipments', 'carrier_invoice_lines', 'original_state', $v)),
                ShipmentFilters::text('zip', 'Zip', fn (Builder $q, string $v): Builder => $q->where('zip', 'like', "{$v}%")),
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
}
