<?php

namespace App\Filament\Resources\CarrierInvoices\Pages;

use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Models\CarrierInvoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewCarrierInvoice extends ViewRecord
{
    protected static string $resource = CarrierInvoiceResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Invoice Details')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('carrier.name')
                                    ->label('Carrier')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'UPS' => 'warning',
                                        'FedEx' => 'info',
                                        default => 'gray',
                                    }),
                                TextEntry::make('filename')
                                    ->label('Filename'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        CarrierInvoice::STATUS_COMPLETED => 'success',
                                        CarrierInvoice::STATUS_PROCESSING => 'warning',
                                        CarrierInvoice::STATUS_FAILED => 'danger',
                                        default => 'gray',
                                    }),
                            ]),
                    ]),
                Section::make('Statistics')
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                TextEntry::make('total_records')
                                    ->label('Total Records')
                                    ->numeric(),
                                TextEntry::make('corrections_count')
                                    ->label('Corrections')
                                    ->numeric(),
                                TextEntry::make('new_corrections')
                                    ->label('New Mappings')
                                    ->numeric()
                                    ->color('success'),
                                TextEntry::make('duplicates')
                                    ->label('Duplicates')
                                    ->numeric()
                                    ->color('gray'),
                                TextEntry::make('total_charges')
                                    ->label('Total Charges')
                                    ->money('USD'),
                            ]),
                    ]),
                Section::make('Processing Info')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('processed_at')
                                    ->label('Processed At')
                                    ->dateTime('M j, Y g:i A'),
                                TextEntry::make('archived_path')
                                    ->label('Archived Path')
                                    ->placeholder('Not archived'),
                                TextEntry::make('error_message')
                                    ->label('Error')
                                    ->placeholder('No errors')
                                    ->color('danger'),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }
}
