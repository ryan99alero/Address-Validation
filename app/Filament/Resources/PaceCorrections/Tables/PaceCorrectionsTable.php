<?php

namespace App\Filament\Resources\PaceCorrections\Tables;

use App\Filament\Exports\PaceCorrectionExporter;
use App\Models\Carrier;
use App\Support\AddressComparison;
use Filament\Actions\ExportBulkAction;
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
                TextColumn::make('csr')
                    ->label('CSR')
                    ->getStateUsing(fn ($record): string => (string) ($record->metadata['csr'] ?? '—'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('metadata->csr', 'like', "%{$search}%")),
                TextColumn::make('sales_person')
                    ->label('Salesperson')
                    ->getStateUsing(fn ($record): string => (string) ($record->metadata['sales_person'] ?? '—'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('metadata->sales_person', 'like', "%{$search}%")),
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
                TextColumn::make('residential')
                    ->label('Residential')
                    ->badge()
                    ->getStateUsing(function ($record): string {
                        $meta = $record->metadata ?? [];
                        $changes = $meta['changes'] ?? [];

                        // Did this push actually set the residential flag on the Pace Contact?
                        if (array_key_exists('residential', $changes)) {
                            return filter_var($changes['residential']['to'] ?? null, FILTER_VALIDATE_BOOLEAN)
                                ? 'Set Residential'
                                : 'Set Commercial';
                        }

                        // Otherwise report what the validator classified it as (no flag change pushed).
                        return match ($meta['residential'] ?? null) {
                            true => 'Residential',
                            false => 'Commercial',
                            default => '—',
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Set Residential' => 'success',
                        'Residential' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => str_contains($state, 'Residential') ? 'heroicon-m-home' : null),
                TextColumn::make('comparison')
                    ->label('Address (original → corrected)')
                    ->html()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        foreach (['original', 'corrected'] as $side) {
                            foreach (['company', 'address1', 'address2', 'city', 'state', 'zip'] as $field) {
                                $q->orWhere("metadata->{$side}->{$field}", 'like', "%{$search}%");
                            }
                        }
                    }))
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
                    // A validation that changed nothing is its own bucket ("No Changes"), even though a
                    // carrier (usually FedEx) was the one that confirmed it — otherwise those rows read as
                    // real FedEx corrections when they aren't.
                    ->getStateUsing(fn ($record): string => self::isNoChange($record)
                        ? 'No Changes'
                        : self::validatorLabel($record->metadata['source'] ?? null))
                    ->color(fn (string $state): string => match ($state) {
                        'No Changes' => 'gray',
                        'Local Cache' => 'info',
                        default => 'warning',
                    }),
            ])
            ->filters([
                // No-change rows (address already clean) are kept for stats — correction rate,
                // coverage — but hidden from the default operational view. Toggle off to see them.
                Filter::make('hide_unchanged')
                    ->label('Hide unchanged (already-clean)')
                    ->toggle()
                    ->default(true)
                    ->query(fn (Builder $query): Builder => $query->whereJsonLength('metadata->changes', '>', 0)),
                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'skipped' => 'Skipped (not validated)',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('source')
                    ->label('Validator')
                    ->options(fn (): array => self::validatorOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $value): Builder => $value === 'no_changes'
                            // No Changes = nothing actually changed, regardless of which validator ran.
                            ? self::whereNoChange($q)
                            // A real correction by this validator — exclude the no-change rows so they
                            // don't double-count under both "FedEx" and "No Changes".
                            : $q->where('metadata->source', $value)->whereJsonLength('metadata->changes', '>', 0),
                    )),
                Filter::make('residential_set')
                    ->label('Residential set on Pace')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereRaw("json_extract(metadata, '\$.changes.residential') is not null")),
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
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Export to Excel')
                    ->exporter(PaceCorrectionExporter::class),
            ]);
    }

    /**
     * Validator filter options: the active address-validation carriers (UPS, FedEx,
     * Smarty…) keyed by their source value, plus the local cache.
     *
     * @return array<string, string>
     */
    protected static function validatorOptions(): array
    {
        $options = ['no_changes' => 'No Changes', 'local_cache' => 'Local Cache'];

        foreach (Carrier::query()->orderBy('name')->pluck('name', 'slug') as $slug => $name) {
            $options[$slug.'_api'] = $name;
        }

        return $options;
    }

    /**
     * A correction record where nothing actually changed — an empty (or absent) changes list. Kept
     * consistent with the "(no changes)" hint on the comparison column.
     */
    protected static function isNoChange(object $record): bool
    {
        return empty($record->metadata['changes'] ?? []);
    }

    /**
     * Constrain a query to no-change rows: an empty changes array OR no changes key at all.
     */
    protected static function whereNoChange(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereJsonLength('metadata->changes', 0)
            ->orWhereNull('metadata->changes'));
    }

    /**
     * Friendly label for a stored validation source value.
     */
    protected static function validatorLabel(?string $source): string
    {
        return match ($source) {
            'local_cache' => 'Local Cache',
            'ups_api' => 'UPS',
            'fedex_api' => 'FedEx',
            'smarty_api' => 'Smarty',
            'usps_api' => 'USPS',
            default => $source ? ucfirst(str_replace('_api', '', $source)) : '—',
        };
    }
}
