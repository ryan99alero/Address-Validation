<?php

namespace App\Filament\Resources\CarrierInvoices\RelationManagers;

use App\Models\ChargeCategory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Fee Breakdown';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('raw_charge_description')
                    ->label('Charge')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'primary' : 'gray')
                    ->placeholder('Uncategorized'),
                TextColumn::make('invoice_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('USD')
                    ->sortable()
                    ->summarize(Sum::make()->money('USD')->label('Total')),
            ])
            ->groups([
                Group::make('tracking_number')
                    ->label('Tracking #')
                    ->collapsible(),
                Group::make('category.name')
                    ->label('Category')
                    ->collapsible(),
            ])
            ->defaultGroup('tracking_number')
            ->defaultSort('amount', 'desc')
            ->filters([
                SelectFilter::make('charge_category_id')
                    ->label('Category')
                    ->options(ChargeCategory::orderBy('name')->pluck('name', 'id'))
                    ->placeholder('All categories'),
            ]);
    }
}
