<?php

namespace App\Filament\Resources\Addresses\Tables;

use App\Models\Address;
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
                TextColumn::make('validation_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'valid' => 'success',
                        'invalid' => 'danger',
                        'ambiguous' => 'warning',
                        'needs_review' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'valid' => 'Valid',
                        'invalid' => 'Invalid',
                        'ambiguous' => 'Ambiguous',
                        'needs_review' => 'Needs Review',
                        'pending' => 'Pending',
                        default => 'Pending',
                    })
                    ->sortable(),
                TextColumn::make('validation_source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'local_cache' => 'success',
                        'fedex_api', 'ups_api', 'usps_api' => 'warning',
                        'manual' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'local_cache' => 'Invoice DB',
                        'fedex_api' => 'FedEx API',
                        'ups_api' => 'UPS API',
                        'usps_api' => 'USPS API',
                        'manual' => 'Manual',
                        default => '-',
                    })
                    ->toggleable(),
                TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->formatStateUsing(fn ($state): string => $state ? number_format($state * 100, 0).'%' : '-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('external_reference')
                    ->label('Reference')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('input_name')
                    ->label('Name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('input_company')
                    ->label('Company')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('input_address_1')
                    ->label('Original Address')
                    ->html()
                    ->wrap()
                    ->searchable()
                    ->getStateUsing(fn (Address $record): string => self::addressBlock([
                        'address1' => $record->input_address_1,
                        'address2' => $record->input_address_2,
                        'city' => $record->input_city,
                        'state' => $record->input_state,
                        'zip' => $record->input_postal,
                    ], self::changedAddressFields($record), 'removed')),
                TextColumn::make('output_address_1')
                    ->label('Corrected Address')
                    ->html()
                    ->wrap()
                    ->searchable(['output_address_1', 'output_city', 'output_state', 'output_postal'])
                    ->getStateUsing(function (Address $record): string {
                        if (empty($record->output_address_1)) {
                            return '<span style="color:#6b7280">Not validated</span>';
                        }

                        return self::addressBlock([
                            'address1' => $record->output_address_1,
                            'address2' => $record->output_address_2,
                            'city' => $record->output_city,
                            'state' => $record->output_state,
                            'zip' => $record->output_postal.($record->output_postal_ext ? '-'.$record->output_postal_ext : ''),
                        ], self::changedAddressFields($record), 'added');
                    }),
                TextColumn::make('input_city')
                    ->label('City')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('input_state')
                    ->label('State')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('input_postal')
                    ->label('ZIP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('classification')
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
                TextColumn::make('validatedByCarrier.name')
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
                        'valid' => 'Valid',
                        'invalid' => 'Invalid',
                        'ambiguous' => 'Ambiguous',
                        'needs_review' => 'Needs Review',
                    ]),

                Filter::make('issues_only')
                    ->label('Issues only (invalid / ambiguous / needs review)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->issues()),

                SelectFilter::make('validation_source')
                    ->label('Validation Source')
                    ->options([
                        'local_cache' => 'Invoice DB',
                        'fedex_api' => 'FedEx API',
                        'ups_api' => 'UPS API',
                        'usps_api' => 'USPS API',
                        'manual' => 'Manual',
                    ]),

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

                        if (str_ends_with($value, '+')) {
                            $threshold = (float) str_replace('+', '', $value) / 100;

                            return $query->where('confidence_score', '>=', $threshold);
                        } elseif (str_ends_with($value, '-')) {
                            $threshold = (float) str_replace('-', '', $value) / 100;

                            return $query->where('confidence_score', '<', $threshold);
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
                    ]),

                SelectFilter::make('is_residential')
                    ->label('Residential')
                    ->options([
                        '1' => 'Yes',
                        '0' => 'No',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! isset($data['value']) || $data['value'] === '') {
                            return $query;
                        }

                        return $query->where('is_residential', $data['value'] === '1');
                    }),

                SelectFilter::make('validated_by_carrier_id')
                    ->label('Carrier')
                    ->options(fn () => Carrier::pluck('name', 'id')->toArray()),

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

    /**
     * Which address fields the validator actually changed (output differs from input).
     *
     * @return array<int, string>
     */
    protected static function changedAddressFields(Address $record): array
    {
        $pairs = [
            'address1' => ['input_address_1', 'output_address_1'],
            'address2' => ['input_address_2', 'output_address_2'],
            'city' => ['input_city', 'output_city'],
            'state' => ['input_state', 'output_state'],
            'zip' => ['input_postal', 'output_postal'],
        ];

        $changed = [];
        foreach ($pairs as $key => [$in, $out]) {
            $outValue = trim((string) $record->{$out});
            if ($outValue === '') {
                continue;
            }
            if (strcasecmp(trim((string) $record->{$in}), $outValue) !== 0) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Render an address as a clean two-line block. Changed fields are highlighted:
     * 'removed' = red strike-through (the old value), 'added' = green/bold (the new value).
     *
     * @param  array<string, mixed>  $addr
     * @param  array<int, string>  $changedFields
     */
    protected static function addressBlock(array $addr, array $changedFields = [], ?string $highlight = null): string
    {
        $fmt = function (string $field) use ($addr, $changedFields, $highlight): string {
            $value = trim((string) ($addr[$field] ?? ''));
            if ($value === '') {
                return '';
            }
            $value = e($value);

            if ($highlight !== null && in_array($field, $changedFields, true)) {
                return $highlight === 'removed'
                    ? '<span style="color:#ef4444;text-decoration:line-through">'.$value.'</span>'
                    : '<span style="color:#22c55e;font-weight:600">'.$value.'</span>';
            }

            return $value;
        };

        $street = trim(implode(' ', array_filter([$fmt('address1'), $fmt('address2')])));
        $cityState = implode(', ', array_filter([$fmt('city'), $fmt('state')]));
        $lastLine = trim(implode(' ', array_filter([$cityState, $fmt('zip')])));
        $lines = array_filter([$street, $lastLine]);

        return empty($lines) ? '<span style="color:#6b7280">—</span>' : implode('<br>', $lines);
    }
}
