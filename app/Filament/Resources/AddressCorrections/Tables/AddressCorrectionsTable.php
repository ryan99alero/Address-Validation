<?php

namespace App\Filament\Resources\AddressCorrections\Tables;

use App\Filament\Resources\AddressCorrections\AddressCorrectionResource;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AddressCorrectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_address')
                    ->label('Corrected (Good) Address')
                    ->state(fn (CorrectedAddress $record): string => $record->full_address)
                    ->weight('bold')
                    ->wrap()
                    ->color('success')
                    ->description(fn (CorrectedAddress $record): ?string => self::sampleOriginal($record))
                    // Search the corrected address itself...
                    ->searchable(['address_1', 'address_2', 'city', 'state', 'postal'])
                    ->sortable(['address_1']),
                // ...and a separate searchable lens over the bad originals (variants).
                TextColumn::make('original_search')
                    ->label('Bad Variations')
                    ->state(fn (CorrectedAddress $record): string => $record->variant_count.' variation'.($record->variant_count === 1 ? '' : 's'))
                    ->badge()
                    ->color(fn (CorrectedAddress $record): string => $record->variant_count > 1 ? 'warning' : 'gray')
                    ->sortable(['variant_count'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereHas(
                        'variants',
                        fn (Builder $q): Builder => $q
                            ->where('input_address_1', 'like', "%{$search}%")
                            ->orWhere('input_city', 'like', "%{$search}%")
                            ->orWhere('input_state', 'like', "%{$search}%")
                            ->orWhere('input_postal', 'like', "%{$search}%")
                    )),
                TextColumn::make('usage_count')
                    ->label('Times Corrected')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('firstCarrier.name')
                    ->label('First Carrier')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_used_at')
                    ->label('Last Seen')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('usage_count', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'firstCarrier',
                'variants' => fn ($q) => $q->orderByDesc('times_seen')->limit(1),
            ]))
            ->filters([
                SelectFilter::make('first_carrier_id')
                    ->label('First Carrier')
                    ->options(Carrier::whereIn('slug', ['ups', 'fedex'])->pluck('name', 'id')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (CorrectedAddress $record): string => AddressCorrectionResource::getUrl('view', ['record' => $record])),
            ]);
    }

    /**
     * Show one representative bad original under the corrected address.
     */
    protected static function sampleOriginal(CorrectedAddress $record): ?string
    {
        $variant = $record->variants->first();
        if (! $variant) {
            return null;
        }

        $parts = array_filter([$variant->input_address_1, $variant->input_city, $variant->input_state, $variant->input_postal]);

        return 'was: '.implode(', ', $parts);
    }
}
