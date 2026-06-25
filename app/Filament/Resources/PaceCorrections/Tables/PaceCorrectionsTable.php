<?php

namespace App\Filament\Resources\PaceCorrections\Tables;

use App\Support\AddressComparison;
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
                TextColumn::make('comparison')
                    ->label('Address (original → corrected)')
                    ->html()
                    ->getStateUsing(function ($record): string {
                        if ($record->status === 'failed') {
                            return '<span style="color:#ef4444">'.e(Str::limit((string) $record->error_message, 140)).'</span>';
                        }

                        $changes = $record->metadata['changes'] ?? [];
                        $original = $record->metadata['original'] ?? AddressComparison::fromChanges($changes, 'from');
                        $corrected = $record->metadata['corrected'] ?? AddressComparison::fromChanges($changes, 'to');
                        $html = AddressComparison::render($original, $corrected)->toHtml();

                        return empty($changes) ? $html.'<div style="color:#6b7280;font-size:0.75rem;margin-top:2px">(no changes)</div>' : $html;
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
}
