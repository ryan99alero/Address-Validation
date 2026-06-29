<?php

namespace App\Filament\Resources\CarrierShipmentSummaries\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Charge Detail';

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return $table
            ->description('Every individual charge billed against this shipment.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('carrier_id', $owner->carrier_id)
                ->whereDate('invoice_date', $owner->invoice_date))
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
