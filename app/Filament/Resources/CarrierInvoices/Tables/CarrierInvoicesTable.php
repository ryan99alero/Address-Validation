<?php

namespace App\Filament\Resources\CarrierInvoices\Tables;

use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CarrierInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'UPS' => 'warning',
                        'FedEx' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('invoice_date')
                    ->label('Invoice Date')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('account_number')
                    ->label('Account')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('total_records')
                    ->label('Shipments')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Charges')
                    ->money('USD')
                    ->getStateUsing(fn (CarrierInvoice $record): float => (float) $record->charges()->sum('amount')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CarrierInvoice::STATUS_COMPLETED => 'success',
                        CarrierInvoice::STATUS_PROCESSING => 'warning',
                        CarrierInvoice::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Imported')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                SelectFilter::make('carrier')
                    ->relationship('carrier', 'name')
                    ->options(Carrier::pluck('name', 'id')),
                SelectFilter::make('status')
                    ->options([
                        CarrierInvoice::STATUS_PENDING => 'Pending',
                        CarrierInvoice::STATUS_PROCESSING => 'Processing',
                        CarrierInvoice::STATUS_COMPLETED => 'Completed',
                        CarrierInvoice::STATUS_FAILED => 'Failed',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (CarrierInvoice $record) => CarrierInvoiceResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
