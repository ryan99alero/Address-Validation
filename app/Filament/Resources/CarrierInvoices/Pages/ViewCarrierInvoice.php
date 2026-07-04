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
                                TextEntry::make('invoice_number')
                                    ->label('Invoice Number')
                                    ->placeholder('—'),
                                TextEntry::make('invoice_date')
                                    ->label('Invoice Date')
                                    ->date('M j, Y')
                                    ->placeholder('—'),
                                TextEntry::make('account_number')
                                    ->label('Account')
                                    ->placeholder('—'),
                                TextEntry::make('filename')
                                    ->label('Filename')
                                    ->placeholder('—'),
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
                                    ->label('Shipments')
                                    ->numeric(),
                                TextEntry::make('correction_records')
                                    ->label('Corrections')
                                    ->numeric(),
                                TextEntry::make('new_corrections')
                                    ->label('New Mappings')
                                    ->numeric()
                                    ->color('success'),
                                TextEntry::make('duplicate_corrections')
                                    ->label('Duplicates')
                                    ->numeric()
                                    ->color('gray'),
                                TextEntry::make('total_correction_charges')
                                    ->label('Total Charges')
                                    ->money('USD'),
                            ]),
                    ]),
                Section::make('Reconciliation')
                    ->description('Whether the imported charges match the invoice. PDF: vs the carrier\'s printed total. CSV: vs the file\'s own charge rows (import completeness).')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('charges_reconciled')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?bool $state): string => $state === null ? 'Not checked' : ($state ? 'Reconciled' : 'Mismatch'))
                                    ->color(fn (?bool $state): string => $state === null ? 'gray' : ($state ? 'success' : 'danger'))
                                    ->icon(fn (?bool $state): ?string => $state === null ? null : ($state ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')),
                                TextEntry::make('charges_parsed_total')
                                    ->label('Imported Total')
                                    ->money('USD')
                                    ->placeholder('—'),
                                TextEntry::make('charges_expected_total')
                                    ->label('Expected (file)')
                                    ->money('USD')
                                    ->placeholder('—'),
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
