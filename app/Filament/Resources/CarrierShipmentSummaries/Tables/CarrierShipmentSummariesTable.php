<?php

namespace App\Filament\Resources\CarrierShipmentSummaries\Tables;

use App\Models\CarrierShipment;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
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
            ->filters([
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
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
