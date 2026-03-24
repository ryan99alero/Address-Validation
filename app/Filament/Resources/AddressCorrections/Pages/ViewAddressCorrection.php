<?php

namespace App\Filament\Resources\AddressCorrections\Pages;

use App\Filament\Resources\AddressCorrections\AddressCorrectionResource;
use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Models\CarrierInvoiceLine;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewAddressCorrection extends ViewRecord
{
    protected static string $resource = AddressCorrectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewInvoice')
                ->label('View Invoice')
                ->icon('heroicon-o-document-text')
                ->url(fn () => CarrierInvoiceResource::getUrl('view', ['record' => $this->record->carrierInvoice])),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Shipment Information')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('tracking_number')
                                    ->label('Tracking Number')
                                    ->copyable()
                                    ->weight('bold'),
                                TextEntry::make('carrierInvoice.carrier.name')
                                    ->label('Carrier')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'UPS' => 'warning',
                                        'FedEx' => 'info',
                                        default => 'gray',
                                    }),
                                TextEntry::make('ship_date')
                                    ->label('Ship Date')
                                    ->date('M j, Y'),
                                TextEntry::make('charge_amount')
                                    ->label('Correction Charge')
                                    ->money('USD')
                                    ->color('danger'),
                            ]),
                    ]),

                Grid::make(2)
                    ->schema([
                        Section::make('Original Address (Bad)')
                            ->schema([
                                TextEntry::make('original_name')
                                    ->label('Name')
                                    ->placeholder('—'),
                                TextEntry::make('original_company')
                                    ->label('Company')
                                    ->placeholder('—'),
                                TextEntry::make('original_address_1')
                                    ->label('Address Line 1')
                                    ->placeholder('—')
                                    ->weight('bold'),
                                TextEntry::make('original_address_2')
                                    ->label('Address Line 2')
                                    ->placeholder('—'),
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('original_city')
                                            ->label('City')
                                            ->placeholder('—'),
                                        TextEntry::make('original_state')
                                            ->label('State')
                                            ->placeholder('—'),
                                    ]),
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('original_postal')
                                            ->label('Postal Code')
                                            ->placeholder('—'),
                                        TextEntry::make('original_country')
                                            ->label('Country')
                                            ->placeholder('—'),
                                    ]),
                            ])
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger'),

                        Section::make('Corrected Address (Good)')
                            ->schema([
                                TextEntry::make('corrected_address_1')
                                    ->label('Address Line 1')
                                    ->placeholder('—')
                                    ->weight('bold'),
                                TextEntry::make('corrected_address_2')
                                    ->label('Address Line 2')
                                    ->placeholder('—'),
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('corrected_city')
                                            ->label('City')
                                            ->placeholder('—'),
                                        TextEntry::make('corrected_state')
                                            ->label('State')
                                            ->placeholder('—'),
                                    ]),
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('corrected_postal')
                                            ->label('Postal Code')
                                            ->placeholder('—'),
                                        TextEntry::make('corrected_country')
                                            ->label('Country')
                                            ->placeholder('—'),
                                    ]),
                            ])
                            ->icon('heroicon-o-check-circle')
                            ->iconColor('success'),
                    ]),

                Section::make('Source Information')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('carrierInvoice.filename')
                                    ->label('Invoice File'),
                                TextEntry::make('shipping_lookup_status')
                                    ->label('Original Address Source')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'info',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        CarrierInvoiceLine::LOOKUP_STATUS_FOUND => 'Shipping Database',
                                        default => 'Carrier Invoice',
                                    }),
                                TextEntry::make('shipping_lookup_at')
                                    ->label('Lookup Time')
                                    ->dateTime('M j, Y g:i A')
                                    ->placeholder('—'),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }
}
