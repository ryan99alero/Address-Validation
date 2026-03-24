<?php

namespace App\Filament\Resources\AddressCorrections\Tables;

use App\Filament\Resources\AddressCorrections\AddressCorrectionResource;
use App\Models\Carrier;
use App\Models\CarrierInvoiceLine;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AddressCorrectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('carrierInvoice.carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'UPS' => 'warning',
                        'FedEx' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('ship_date')
                    ->label('Ship Date')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('original_full_address')
                    ->label('Original Address')
                    ->limit(35)
                    ->tooltip(fn ($record) => $record->original_full_address ?: 'No original address'),
                TextColumn::make('corrected_full_address')
                    ->label('Corrected Address')
                    ->limit(35)
                    ->tooltip(fn ($record) => $record->corrected_full_address),
                TextColumn::make('charge_amount')
                    ->label('Charge')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('shipping_lookup_status')
                    ->label('Source')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'Shipping DB',
                        default => 'Invoice',
                    }),
            ])
            ->defaultSort('ship_date', 'desc')
            ->filters([
                SelectFilter::make('carrier')
                    ->label('Carrier')
                    ->options(Carrier::pluck('name', 'id'))
                    ->query(fn ($query, $data) => $data['value']
                        ? $query->whereHas('carrierInvoice', fn ($q) => $q->where('carrier_id', $data['value']))
                        : $query
                    ),
                SelectFilter::make('has_original')
                    ->label('Has Original Address')
                    ->options([
                        'yes' => 'Yes',
                        'no' => 'No',
                    ])
                    ->query(fn ($query, $data) => match ($data['value']) {
                        'yes' => $query->whereNotNull('original_address_1'),
                        'no' => $query->whereNull('original_address_1'),
                        default => $query,
                    }),
                SelectFilter::make('source')
                    ->label('Address Source')
                    ->options([
                        'invoice' => 'From Invoice',
                        'shipping_db' => 'From Shipping DB',
                    ])
                    ->query(fn ($query, $data) => match ($data['value']) {
                        'invoice' => $query->whereNull('shipping_lookup_status'),
                        'shipping_db' => $query->where('shipping_lookup_status', CarrierInvoiceLine::LOOKUP_STATUS_FOUND),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (CarrierInvoiceLine $record) => AddressCorrectionResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
