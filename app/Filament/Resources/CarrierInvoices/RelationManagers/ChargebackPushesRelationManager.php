<?php

namespace App\Filament\Resources\CarrierInvoices\RelationManagers;

use App\Filament\Support\ChargebackPushTable;
use App\Filament\Support\DateRangeFilter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Pace JobCost chargeback pushes originating from this invoice's charges (absorbed from the
 * standalone Chargeback Pushes page). Read-only — the cross-invoice ledger + CSV export live on
 * the global Chargeback Pushes page, reachable from the Invoices list header.
 */
class ChargebackPushesRelationManager extends RelationManager
{
    protected static string $relationship = 'chargebackPushes';

    protected static ?string $title = 'Chargeback Pushes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'pushed' => 'success',
                        'failed', 'unverified' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('tracking_number')->label('Tracking')->searchable()->fontFamily('mono'),
                TextColumn::make('driver')->badge()->toggleable(),
                TextColumn::make('activity_code')->label('Activity')->badge(),
                TextColumn::make('amount')->money('USD')->sortable()->alignEnd(),
                ...ChargebackPushTable::flagColumns(),
                TextColumn::make('pace_job')->label('Job')->searchable(),
                ...ChargebackPushTable::repColumns(),
                TextColumn::make('pace_customer_id')->label('Customer #')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pace_jobcost_id')->label('JobCost ID')->fontFamily('mono')->placeholder('—')->searchable(),
                TextColumn::make('pushed_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(60)->color('danger')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                ChargebackPushTable::viewFilter(),
                DateRangeFilter::make('ship_date', 'Ship date'),
            ])
            ->recordActions(ChargebackPushTable::reviewActions())
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
