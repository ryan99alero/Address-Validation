<?php

namespace App\Filament\Resources\CarrierCharges\Tables;

use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Models\CarrierCharge;
use App\Models\ChargeCategory;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CarrierChargesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->searchable(isIndividual: true)
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->sortable(),
                TextColumn::make('raw_charge_description')
                    ->label('Adjustment / Charge')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'primary' : 'gray')
                    ->placeholder('Uncategorized'),
                TextColumn::make('amount')
                    ->money('USD')
                    ->sortable()
                    ->summarize(Sum::make()->money('USD')->label('Total')),
                TextColumn::make('invoice_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('invoice.filename')
                    ->label('Invoice')
                    ->url(fn (CarrierCharge $record): ?string => $record->invoice
                        ? CarrierInvoiceResource::getUrl('view', ['record' => $record->invoice])
                        : null)
                    ->color('primary')
                    ->limit(28)
                    ->placeholder('—'),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->relationship('carrier', 'name'),
                SelectFilter::make('charge_category_id')
                    ->label('Category')
                    ->options(fn () => ChargeCategory::orderBy('name')->pluck('name', 'id')),
            ])
            // 3.4M+ rows — wait for a tracking search / filter before querying.
            ->deferLoading()
            ->paginated([25, 50, 100]);
    }
}
