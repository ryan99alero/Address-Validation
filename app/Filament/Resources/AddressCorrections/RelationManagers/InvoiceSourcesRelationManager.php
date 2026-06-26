<?php

namespace App\Filament\Resources\AddressCorrections\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoiceLines';

    protected static ?string $title = 'Invoice Sources';

    public function table(Table $table): Table
    {
        return $table
            ->description('The carrier-invoice lines (file, location, and tracking #) this correction was extracted from.')
            ->columns([
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('carrierInvoice.filename')
                    ->label('File / Location')
                    ->wrap()
                    ->description(fn ($record): ?string => $record->carrierInvoice?->archived_path ?? $record->carrierInvoice?->original_path)
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('original_full_address')
                    ->label('Original (Bad) Address')
                    ->color('danger')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('change_type')
                    ->label('Change')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('ship_date')
                    ->label('Ship Date')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('charge_amount')
                    ->label('Fee')
                    ->money('USD')
                    ->alignEnd()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('ship_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('carrierInvoice'))
            ->paginated([25, 50, 100]);
    }
}
