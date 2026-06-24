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
                TextColumn::make('changes')
                    ->label('Field changes (old → new)')
                    ->html()
                    ->wrap()
                    ->getStateUsing(function ($record): string {
                        if ($record->status === 'failed') {
                            return '<span style="color:#dc2626">'.e(Str::limit((string) $record->error_message, 140)).'</span>';
                        }

                        $changes = $record->metadata['changes'] ?? [];
                        if (empty($changes)) {
                            return '<span style="color:#6b7280">validated — no changes</span>';
                        }

                        $lines = [];
                        foreach ($changes as $field => $fromTo) {
                            $from = (string) ($fromTo['from'] ?? '');
                            $to = (string) ($fromTo['to'] ?? '');
                            $lines[] = '<strong>'.e($field).'</strong>: '.e($from).' → <strong>'.e($to).'</strong>';
                        }

                        return implode('<br>', $lines);
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
