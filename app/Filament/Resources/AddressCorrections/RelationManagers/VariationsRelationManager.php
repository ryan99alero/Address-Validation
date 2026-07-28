<?php

namespace App\Filament\Resources\AddressCorrections\RelationManagers;

use App\Models\AddressVariant;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class VariationsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Bad Address Variations';

    /** @var array<string, array<string, mixed>>|null memoized per-variant tracking + Pace rep data */
    private ?array $occ = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function occ(): array
    {
        return $this->occ ??= $this->getOwnerRecord()->variantOccurrences();
    }

    private function occField(AddressVariant $record, string $key): ?string
    {
        $value = $this->occ()[$record->input_hash][$key] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Every bad/original address corrected to the good address above. Mark any "Do Not Use" to stop the engine returning that correction — it stays on record (not deleted), so a re-import keeps it flagged.')
            ->columns([
                IconColumn::make('is_active')
                    ->label('Usable')
                    ->boolean(),
                TextColumn::make('input_address_1')
                    ->label('Original (Bad) Address')
                    ->weight('bold')
                    ->wrap()
                    ->color(fn ($record): string => $record->is_active ? 'danger' : 'gray')
                    ->description(fn ($record): ?string => trim(implode(', ', array_filter([
                        $record->input_address_2, $record->input_city, $record->input_state, $record->input_postal,
                    ]))) ?: null)
                    ->searchable(['input_address_1', 'input_address_2', 'input_city', 'input_state', 'input_postal']),
                TextColumn::make('times_seen')
                    ->label('Times Seen')
                    ->badge()
                    ->color('warning')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('inactive_reason')
                    ->label('Status')
                    ->placeholder('Usable')
                    ->color('gray'),
                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('recent_tracking')
                    ->label('Recent Tracking')
                    ->state(fn (AddressVariant $record): ?string => $this->occField($record, 'tracking'))
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('shipment_date')
                    ->label('Shipment Date')
                    ->state(fn (AddressVariant $record): ?string => $this->occField($record, 'date'))
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->tooltip('Ship date of the most recent correction (falls back to the invoice date)'),
                TextColumn::make('job')
                    ->label('Job #')
                    ->state(fn (AddressVariant $record): ?string => $this->occField($record, 'job'))
                    ->placeholder('—'),
                TextColumn::make('customer')
                    ->label('Customer')
                    ->state(function (AddressVariant $record): ?string {
                        $id = $this->occField($record, 'customer_id');
                        $name = $this->occField($record, 'customer_name');

                        return trim(($id ?? '').($id !== null && $name !== null ? ' · ' : '').($name ?? '')) ?: null;
                    })
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('csr')
                    ->label('CSR')
                    ->state(fn (AddressVariant $record): ?string => $this->occField($record, 'csr'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('salesperson')
                    ->label('Sales Rep')
                    ->state(fn (AddressVariant $record): ?string => $this->occField($record, 'salesperson'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('times_seen', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Usable'),
            ])
            ->recordActions([
                Action::make('toggleUsable')
                    ->label(fn (AddressVariant $record): string => $record->is_active ? 'Do Not Use' : 'Re-enable')
                    ->icon(fn (AddressVariant $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (AddressVariant $record): string => $record->is_active ? 'danger' : 'success')
                    ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false)
                    ->requiresConfirmation()
                    ->action(function (AddressVariant $record): void {
                        abort_unless(Auth::user()?->isAdmin() ?? false, 403);

                        $record->update([
                            'is_active' => ! $record->is_active,
                            'inactive_reason' => $record->is_active ? 'Marked Do Not Use' : null,
                        ]);
                    }),
            ])
            ->paginated([25, 50, 100]);
    }
}
