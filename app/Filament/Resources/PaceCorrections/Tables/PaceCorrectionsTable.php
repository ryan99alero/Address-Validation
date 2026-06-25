<?php

namespace App\Filament\Resources\PaceCorrections\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PaceCorrectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('job_number')
                    ->label('Job #')
                    ->getStateUsing(fn ($record): string => (string) ($record->metadata['job_number'] ?? '—'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('metadata->job_number', 'like', "%{$search}%")),
                TextColumn::make('shipment_id')
                    ->label('Shipment')
                    ->getStateUsing(fn ($record): string => (string) ($record->metadata['shipment_id'] ?? '—'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('metadata->shipment_id', 'like', "%{$search}%")),
                TextColumn::make('contact_id')
                    ->label('Contact')
                    ->getStateUsing(fn ($record): string => (string) ($record->metadata['contact_id'] ?? '—'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('metadata->contact_id', 'like', "%{$search}%")),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'skipped' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('mode')
                    ->label('Mode')
                    ->badge()
                    ->getStateUsing(fn ($record): string => ($record->metadata['dry_run'] ?? false) ? 'Dry-run' : 'Live')
                    ->color(fn (string $state): string => $state === 'Dry-run' ? 'info' : 'gray'),
                TextColumn::make('original')
                    ->label('Original address')
                    ->html()
                    ->wrap()
                    ->getStateUsing(fn ($record): string => self::addressBlock(
                        $record->metadata['original'] ?? self::sideFromChanges($record->metadata['changes'] ?? [], 'from'),
                        array_keys($record->metadata['changes'] ?? []),
                        'removed'
                    )),
                TextColumn::make('corrected')
                    ->label('Corrected address')
                    ->html()
                    ->wrap()
                    ->getStateUsing(function ($record): string {
                        if ($record->status === 'failed') {
                            return '<span style="color:#ef4444">'.e(Str::limit((string) $record->error_message, 120)).'</span>';
                        }

                        $changed = array_keys($record->metadata['changes'] ?? []);
                        $block = self::addressBlock(
                            $record->metadata['corrected'] ?? self::sideFromChanges($record->metadata['changes'] ?? [], 'to'),
                            $changed,
                            'added'
                        );

                        return empty($changed) ? $block.'<br><span style="color:#6b7280">(no changes)</span>' : $block;
                    }),
                TextColumn::make('source')
                    ->label('Validator')
                    ->badge()
                    ->getStateUsing(fn ($record): string => (string) ($record->metadata['source'] ?? '—')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'skipped' => 'Skipped (not validated)',
                        'failed' => 'Failed',
                    ]),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
            ]);
    }

    /**
     * Render an address as a clean two-line block. Changed fields are highlighted:
     * 'removed' = red strike-through (the old value), 'added' = green/bold (the new value).
     *
     * @param  array<string, mixed>|null  $addr
     * @param  array<int, string>  $changedFields
     */
    protected static function addressBlock(?array $addr, array $changedFields = [], ?string $highlight = null): string
    {
        $addr = $addr ?: [];
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

    /**
     * Fallback for rows logged before original/corrected snapshots existed:
     * reconstruct a partial address from the changes diff.
     *
     * @param  array<string, array{from?: mixed, to?: mixed}>  $changes
     * @return array<string, mixed>
     */
    protected static function sideFromChanges(array $changes, string $side): array
    {
        $address = [];
        foreach ($changes as $field => $fromTo) {
            $address[$field] = $fromTo[$side] ?? null;
        }

        return $address;
    }
}
