<?php

namespace App\Filament\Resources\CarrierInvoices\RelationManagers;

use App\Models\CarrierInvoiceLine;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CorrectionLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'correctionLines';

    protected static ?string $title = 'Address Corrections';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('ship_date')
                    ->label('Ship Date')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('original_full_address')
                    ->label('Original Address')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->original_full_address),
                TextColumn::make('corrected_full_address')
                    ->label('Corrected Address')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->corrected_full_address),
                TextColumn::make('charge_amount')
                    ->label('Charge')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('shipping_lookup_status')
                    ->label('Lookup')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : '-'),
            ])
            ->defaultSort('ship_date', 'desc')
            ->filters([
                SelectFilter::make('shipping_lookup_status')
                    ->label('Lookup Status')
                    ->options([
                        CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'Found (from Shipping DB)',
                        '' => 'From Invoice',
                    ]),
            ])
            ->actions([
                ViewAction::make()
                    ->infolist(fn (Schema $schema) => $schema
                        ->schema([
                            Section::make('Tracking Information')
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('tracking_number')
                                                ->label('Tracking Number')
                                                ->copyable(),
                                            TextEntry::make('ship_date')
                                                ->label('Ship Date')
                                                ->date('M j, Y'),
                                            TextEntry::make('charge_amount')
                                                ->label('Charge')
                                                ->money('USD'),
                                        ]),
                                ]),
                            Section::make('Original Address (Bad)')
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextEntry::make('original_name')
                                                ->label('Name')
                                                ->placeholder('-'),
                                            TextEntry::make('original_company')
                                                ->label('Company')
                                                ->placeholder('-'),
                                        ]),
                                    TextEntry::make('original_address_1')
                                        ->label('Address Line 1')
                                        ->placeholder('-'),
                                    TextEntry::make('original_address_2')
                                        ->label('Address Line 2')
                                        ->placeholder('-'),
                                    Grid::make(4)
                                        ->schema([
                                            TextEntry::make('original_city')
                                                ->label('City')
                                                ->placeholder('-'),
                                            TextEntry::make('original_state')
                                                ->label('State')
                                                ->placeholder('-'),
                                            TextEntry::make('original_postal')
                                                ->label('Postal')
                                                ->placeholder('-'),
                                            TextEntry::make('original_country')
                                                ->label('Country')
                                                ->placeholder('-'),
                                        ]),
                                ])
                                ->icon('heroicon-o-x-circle')
                                ->iconColor('danger'),
                            Section::make('Corrected Address (Good)')
                                ->schema([
                                    TextEntry::make('corrected_address_1')
                                        ->label('Address Line 1')
                                        ->placeholder('-'),
                                    TextEntry::make('corrected_address_2')
                                        ->label('Address Line 2')
                                        ->placeholder('-'),
                                    Grid::make(4)
                                        ->schema([
                                            TextEntry::make('corrected_city')
                                                ->label('City')
                                                ->placeholder('-'),
                                            TextEntry::make('corrected_state')
                                                ->label('State')
                                                ->placeholder('-'),
                                            TextEntry::make('corrected_postal')
                                                ->label('Postal')
                                                ->placeholder('-'),
                                            TextEntry::make('corrected_country')
                                                ->label('Country')
                                                ->placeholder('-'),
                                        ]),
                                ])
                                ->icon('heroicon-o-check-circle')
                                ->iconColor('success'),
                            Section::make('Lookup Info')
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextEntry::make('shipping_lookup_status')
                                                ->label('Shipping DB Lookup')
                                                ->badge()
                                                ->color(fn ($state) => match ($state) {
                                                    CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'success',
                                                    default => 'gray',
                                                })
                                                ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : 'N/A (from invoice)'),
                                            TextEntry::make('shipping_lookup_at')
                                                ->label('Lookup Time')
                                                ->dateTime('M j, Y g:i A')
                                                ->placeholder('-'),
                                        ]),
                                ])
                                ->collapsed(),
                        ])
                    ),
            ]);
    }
}
