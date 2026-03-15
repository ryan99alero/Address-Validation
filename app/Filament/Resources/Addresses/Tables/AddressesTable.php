<?php

namespace App\Filament\Resources\Addresses\Tables;

use App\Models\AddressCorrection;
use App\Models\Carrier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AddressesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('latestCorrection.validation_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        AddressCorrection::STATUS_VALID => 'success',
                        AddressCorrection::STATUS_INVALID => 'danger',
                        AddressCorrection::STATUS_AMBIGUOUS => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        AddressCorrection::STATUS_VALID => 'Valid',
                        AddressCorrection::STATUS_INVALID => 'Invalid',
                        AddressCorrection::STATUS_AMBIGUOUS => 'Ambiguous',
                        default => 'Pending',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('address_corrections as ac_status', function ($join) {
                                $join->on('ac_status.address_id', '=', 'addresses.id')
                                    ->whereRaw('ac_status.id = (select max(id) from address_corrections where address_id = addresses.id)');
                            })
                            ->orderBy('ac_status.validation_status', $direction)
                            ->select('addresses.*');
                    }),
                TextColumn::make('latestCorrection.confidence_score')
                    ->label('Confidence')
                    ->formatStateUsing(fn ($state): string => $state ? number_format($state * 100, 0).'%' : '-')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('address_corrections as ac_conf', function ($join) {
                                $join->on('ac_conf.address_id', '=', 'addresses.id')
                                    ->whereRaw('ac_conf.id = (select max(id) from address_corrections where address_id = addresses.id)');
                            })
                            ->orderBy('ac_conf.confidence_score', $direction)
                            ->select('addresses.*');
                    })
                    ->toggleable(),
                TextColumn::make('external_reference')
                    ->label('Reference')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('company')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address_line_1')
                    ->label('Address')
                    ->searchable()
                    ->wrap()
                    ->limit(50),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('state')
                    ->searchable(),
                TextColumn::make('postal_code')
                    ->label('ZIP')
                    ->searchable(),
                TextColumn::make('latestCorrection.classification')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'residential' => 'info',
                        'commercial' => 'success',
                        'mixed' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'Unknown'))
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'import' => 'info',
                        'manual' => 'success',
                        'api' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('latestCorrection.carrier.name')
                    ->label('Carrier')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('importBatch.name')
                    ->label('Import Batch')
                    ->description(fn ($record) => $record->importBatch?->completed_at?->format('M j, Y g:i A'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('validation_status')
                    ->label('Validation Status')
                    ->options([
                        'pending' => 'Pending',
                        AddressCorrection::STATUS_VALID => 'Valid',
                        AddressCorrection::STATUS_INVALID => 'Invalid',
                        AddressCorrection::STATUS_AMBIGUOUS => 'Ambiguous',
                    ])
                    ->query(function ($query, array $data) {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        if ($data['value'] === 'pending') {
                            return $query->whereDoesntHave('corrections');
                        }

                        return $query->whereHas('latestCorrection', function ($q) use ($data) {
                            $q->where('validation_status', $data['value']);
                        });
                    }),

                SelectFilter::make('confidence')
                    ->label('Confidence Score')
                    ->options([
                        '90+' => '90%+ (High)',
                        '80+' => '80%+ (Good)',
                        '70+' => '70%+ (Medium)',
                        '50+' => '50%+ (Low)',
                        '50-' => 'Below 50% (Poor)',
                        '40-' => 'Below 40%',
                        '30-' => 'Below 30%',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        $value = $data['value'];

                        // Parse the filter value (e.g., "90+", "50-")
                        if (str_ends_with($value, '+')) {
                            $threshold = (float) str_replace('+', '', $value) / 100;

                            return $query->whereHas('latestCorrection', function ($q) use ($threshold) {
                                $q->where('confidence_score', '>=', $threshold);
                            });
                        } elseif (str_ends_with($value, '-')) {
                            $threshold = (float) str_replace('-', '', $value) / 100;

                            return $query->whereHas('latestCorrection', function ($q) use ($threshold) {
                                $q->where('confidence_score', '<', $threshold);
                            });
                        }

                        return $query;
                    }),

                SelectFilter::make('classification')
                    ->label('Address Type')
                    ->options([
                        'residential' => 'Residential',
                        'commercial' => 'Commercial',
                        'mixed' => 'Mixed',
                        'unknown' => 'Unknown',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('latestCorrection', function ($q) use ($data) {
                            $q->where('classification', $data['value']);
                        });
                    }),

                SelectFilter::make('dpv_status')
                    ->label('DPV Status')
                    ->options([
                        'confirmed' => 'Confirmed (Y)',
                        'secondary_missing' => 'Secondary Missing (S)',
                        'not_confirmed' => 'Not Confirmed (N)',
                        'dpv_match' => 'DPV Match',
                        'any_valid' => 'Any Valid DPV',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('latestCorrection', function ($q) use ($data) {
                            $value = $data['value'];

                            // DPV status is stored in raw_response, need to search JSON
                            match ($value) {
                                'confirmed' => $q->where(function ($subQ) {
                                    // Smarty: dpv_match_code = Y
                                    $subQ->whereJsonContains('raw_response->0->analysis->dpv_match_code', 'Y')
                                        // Or UPS ValidAddressIndicator
                                        ->orWhereNotNull('raw_response->XAVResponse->ValidAddressIndicator')
                                        // Or FedEx DPV = true
                                        ->orWhere('raw_response->output->resolvedAddresses->0->attributes->DPV', 'true');
                                }),
                                'secondary_missing' => $q->whereJsonContains('raw_response->0->analysis->dpv_match_code', 'S'),
                                'not_confirmed' => $q->whereJsonContains('raw_response->0->analysis->dpv_match_code', 'N'),
                                'dpv_match' => $q->where(function ($subQ) {
                                    $subQ->whereIn('raw_response->0->analysis->dpv_match_code', ['Y', 'S', 'D'])
                                        ->orWhereNotNull('raw_response->XAVResponse->ValidAddressIndicator');
                                }),
                                'any_valid' => $q->whereIn('validation_status', ['valid']),
                                default => $q,
                            };
                        });
                    }),

                SelectFilter::make('carrier')
                    ->label('Carrier')
                    ->options(fn () => Carrier::pluck('name', 'id')->toArray())
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('latestCorrection', function ($q) use ($data) {
                            $q->where('carrier_id', $data['value']);
                        });
                    }),

                SelectFilter::make('source')
                    ->options([
                        'import' => 'Import',
                        'manual' => 'Manual',
                        'api' => 'API',
                    ]),
                SelectFilter::make('import_batch_id')
                    ->label('Import Batch')
                    ->relationship('importBatch', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => sprintf(
                        '%s (%s) - %s rows',
                        $record->name ?? $record->original_filename,
                        $record->completed_at?->format('M j, Y') ?? $record->created_at->format('M j, Y'),
                        number_format($record->successful_rows ?? $record->total_rows ?? 0)
                    ))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
