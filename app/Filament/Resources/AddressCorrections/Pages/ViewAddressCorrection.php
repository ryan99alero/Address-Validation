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
                Section::make('Superseded')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->visible(fn ($record): bool => $record->isSuperseded())
                    ->schema([
                        TextEntry::make('superseded_notice')
                            ->hiddenLabel()
                            ->state(function ($record): string {
                                $date = $record->superseded_at?->format('M j, Y');

                                return 'This form was superseded'.($date ? ' on '.$date : '')
                                    .' — the engine now resolves to '.$record->resolveTerminal()->full_address.'.';
                            })
                            ->color('warning')
                            ->weight('bold'),
                    ]),

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
                                    ->state(fn ($record): int => (int) $record->invoiceLines()->count())
                                    ->tooltip('Address-correction fees charged for this address across all carrier invoices'),
                                TextEntry::make('is_residential')
                                    ->label('Type')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state === null ? 'Unknown' : ($state ? 'Residential' : 'Commercial')),
                                TextEntry::make('firstCarrier.name')
                                    ->label('First Carrier')
                                    ->badge()
                                    ->placeholder('—'),
                                TextEntry::make('last_corrected')
                                    ->label('Last Corrected')
                                    ->state(fn ($record): ?string => $record->latestCorrectionDate())
                                    ->date('M j, Y')
                                    ->placeholder('—')
                                    ->tooltip('Ship date of the most recent correction (falls back to the invoice date)'),
                            ]),
                    ]),

                Section::make('Correction Chain')
                    ->description('Every form this address was corrected through, oldest to current.')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->collapsible()
                    ->visible(fn ($record): bool => count($record->chainToTerminal()) > 1 || $record->supersedes()->exists())
                    ->schema([
                        TextEntry::make('chain')
                            ->hiddenLabel()
                            ->html()
                            ->state(function ($record): string {
                                $parts = [];
                                foreach ($record->chainToTerminal() as $node) {
                                    $label = e($node->full_address);
                                    $parts[] = $node->superseded_by_id === null ? "<strong>{$label} — current</strong>" : $label;
                                }
                                $line = implode(' &rarr; ', $parts);
                                $into = $record->supersedes()->count();

                                return $into > 0 ? $line.'<br>'.$into.' earlier form(s) resolve into this address.' : $line;
                            }),
                    ]),

                Section::make('Carrier Verification')
                    ->description('When each carrier last confirmed this address ships without a correction fee.')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        TextEntry::make('verification')
                            ->hiddenLabel()
                            ->html()
                            ->state(function ($record): string {
                                $rows = $record->verifications()->with('carrier')->get();
                                if ($rows->isEmpty()) {
                                    return 'Not yet verified against any carrier.';
                                }

                                return $rows->map(function ($v): string {
                                    $when = $v->verified_at?->format('M j, Y') ?? '—';

                                    return e(($v->carrier->name ?? 'Carrier').': '.$v->status.' ('.$when.')');
                                })->implode('<br>');
                            }),
                    ]),
            ]);
    }
}
