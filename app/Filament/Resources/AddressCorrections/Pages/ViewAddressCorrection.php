<?php

namespace App\Filament\Resources\AddressCorrections\Pages;

use App\Filament\Resources\AddressCorrections\AddressCorrectionResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewAddressCorrection extends ViewRecord
{
    protected static string $resource = AddressCorrectionResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Corrected (Good) Address')
                    ->description('The single correct address. Every bad variation below was corrected to this.')
                    ->icon('heroicon-o-check-circle')
                    ->iconColor('success')
                    ->schema([
                        TextEntry::make('address_1')
                            ->label('Address Line 1')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('address_2')
                            ->label('Address Line 2')
                            ->placeholder('—'),
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('city')->placeholder('—'),
                                TextEntry::make('state')->placeholder('—'),
                                TextEntry::make('postal')
                                    ->label('ZIP')
                                    ->formatStateUsing(fn ($state, $record): string => $record->postal_ext ? "{$state}-{$record->postal_ext}" : (string) $state),
                                TextEntry::make('country')->placeholder('—'),
                            ]),
                    ]),

                Section::make('Summary')
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                TextEntry::make('variant_count')
                                    ->label('Bad Variations')
                                    ->badge()
                                    ->color('warning'),
                                TextEntry::make('times_corrected')
                                    ->label('Times Corrected')
                                    ->state(fn ($record): int => (int) $record->variants()->sum('times_seen')),
                                TextEntry::make('is_residential')
                                    ->label('Type')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state === null ? 'Unknown' : ($state ? 'Residential' : 'Commercial')),
                                TextEntry::make('firstCarrier.name')
                                    ->label('First Carrier')
                                    ->badge()
                                    ->placeholder('—'),
                                TextEntry::make('last_used_at')
                                    ->label('Last Seen')
                                    ->date('M j, Y')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
