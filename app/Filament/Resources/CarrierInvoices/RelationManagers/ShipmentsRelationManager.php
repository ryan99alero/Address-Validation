<?php

namespace App\Filament\Resources\CarrierInvoices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Per-shipment costs for this invoice (absorbed from the standalone Per-Shipment Costs page).
 * Read-only — shipments are extracted from the carrier PDF at ingest, not entered by hand.
 */
class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Per-Shipment Costs';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracking_number')->label('Tracking #')->searchable()->copyable()->fontFamily('mono')->placeholder('—'),
                TextColumn::make('service')->searchable()->placeholder('—'),
                TextColumn::make('zone')->alignEnd()->placeholder('—'),
                TextColumn::make('billed_weight')->label('Billed Wt')->numeric()->alignEnd()->placeholder('—'),
                TextColumn::make('ship_date')->label('Ship Date')->date('M j, Y')->sortable()->placeholder('—'),
                IconColumn::make('is_third_party')->label('3rd Party')->boolean(),
                TextColumn::make('printed_total')->label('Total')->money('USD')->sortable()->alignEnd()
                    ->summarize(Sum::make()->money('USD')->label('Total')),
            ])
            ->filters([
                TernaryFilter::make('is_third_party')->label('Third-party billed'),
            ])
            ->defaultSort('printed_total', 'desc')
            ->paginated([25, 50, 100]);
    }
}
