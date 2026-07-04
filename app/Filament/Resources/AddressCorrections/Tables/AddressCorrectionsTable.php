<?php

namespace App\Filament\Resources\AddressCorrections\Tables;

use App\Filament\Resources\AddressCorrections\AddressCorrectionResource;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Support\AddressComparison;
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
                    ->label('Variation → Corrected (good)')
                    ->html()
                    ->state(function (CorrectedAddress $record): string {
                        $variant = $record->variants->first();

                        $original = $variant ? [
                            'address1' => $variant->input_address_1,
                            'city' => $variant->input_city,
                            'state' => $variant->input_state,
                            'zip' => $variant->input_postal,
                        ] : [];

                        $corrected = [
                            'address1' => $record->address_1,
                            'address2' => $record->address_2,
                            'address3' => $record->address_3,
                            'city' => $record->city,
                            'state' => $record->state,
                            'zip' => $record->postal,
                        ];

                        return AddressComparison::render($original, $corrected)->toHtml();
                    })
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
                TextColumn::make('variants_sum_times_seen')
                    ->label('Times Corrected')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->placeholder('0'),
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
            ->defaultSort('variants_sum_times_seen', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('variants as variants_sum_times_seen', 'times_seen')
                ->with([
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
}
