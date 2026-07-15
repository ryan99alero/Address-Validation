<?php

namespace App\Filament\Resources\CarrierShipmentSummaries\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChargesRelationManager extends RelationManager
{
    // Matched by tracking within the invoice (works for FedEx-derived shipments,
    // which aren't linked to charges by carrier_shipment_id).
    protected static string $relationship = 'invoiceCharges';

    protected static ?string $title = 'Charge Detail';

    public function table(Table $table): Table
    {
        return $table
            ->description('Every individual charge billed against this shipment.')
            ->columns([
                TextColumn::make('raw_charge_description')
                    ->label('Charge / Adjustment')
                    ->wrap(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->placeholder('Uncategorized'),
                TextColumn::make('raw_charge_code')
                    ->label('Code')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('USD')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('USD')->label('Total')),
            ])
            ->defaultSort('amount', 'desc')
            ->paginated([25, 50, 100]);
    }
}
