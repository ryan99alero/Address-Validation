<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ScopedTableSearch;
use App\Filament\Support\CartonReferenceColumns;
use App\Filament\Support\ChargebackPushTable;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\ShipmentFilters;
use App\Models\ChargebackPush;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The chargeback ledger — every carrier charge pushed (or considered) as a Pace JobCost, with its
 * disposition and the returned JobCost id. Read-only view + CSV export (all fields incl. the notes
 * with the recorded→corrected address), so finance can trust it and chase failed/unverified rows.
 */
class ChargebackPushes extends Page implements HasTable
{
    use InteractsWithTable {
        applyGlobalSearchToTableQuery as protected applyDefaultGlobalSearch;
    }
    use ScopedTableSearch;

    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        // Page uses the trait directly, so parent:: can't reach it — call the aliased original.
        return $this->applyScopedColumnSearch($query) ?? $this->applyDefaultGlobalSearch($query);
    }

    protected string $view = 'filament.pages.chargeback-pushes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Costs';

    protected static ?string $navigationLabel = 'Chargeback Pushes';

    protected static ?int $navigationSort = 4;

    // Global cross-invoice recoup ledger: every carrier charge pushed to Pace as a JobCost, with CSV
    // export. Also available per-invoice as a relation-manager tab on the invoice.
    protected static ?string $title = 'Chargeback Pushes (Pace JobCost)';

    public static function getNavigationBadge(): ?string
    {
        // Everything awaiting a human: rows to chase (failed/unverified), duplicates awaiting reversal,
        // and near-duplicates held for review.
        $n = ChargebackPush::whereIn('status', ['failed', 'unverified', ChargebackPush::STATUS_QUARANTINED])
            ->orWhere('reversal_state', ChargebackPush::REVERSAL_NEEDS)
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ChargebackPush::query()->with(['carrier', 'invoice', 'category', 'cartonCost'])->latest('id'))
            ->columns([
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'pushed' => 'success',
                        'failed', 'unverified' => 'danger',
                        'pending' => 'warning',
                        default => 'gray', // skipped_*
                    }),
                TextColumn::make('line_state')
                    ->label('Line')
                    ->badge()
                    ->state(fn (ChargebackPush $r): string => $r->carrier_charge_id ? 'Linked' : 'Orphaned')
                    ->color(fn (string $state): string => $state === 'Orphaned' ? 'warning' : 'gray')
                    ->tooltip(fn (ChargebackPush $r): ?string => $r->carrier_charge_id ? null : 'Backing charge line was deleted on re-import — may be a stale/duplicate push. Audit before trusting.')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_number')->label('Tracking')->searchable()->fontFamily('mono'),
                TextColumn::make('driver')->badge()->toggleable(),
                TextColumn::make('activity_code')->label('Activity')->badge(),
                TextColumn::make('amount')->money('USD')->sortable()->alignEnd(),
                ...ChargebackPushTable::flagColumns(),
                TextColumn::make('pace_job')->label('Job')->searchable(),
                ...ChargebackPushTable::repColumns(),
                ...CartonReferenceColumns::make(),
                TextColumn::make('pace_customer_id')->label('Customer ID')->searchable()->toggleable(),
                TextColumn::make('pace_jobcost_id')->label('JobCost ID')->fontFamily('mono')->placeholder('—')->searchable(),
                TextColumn::make('carrier.name')->label('Carrier')->badge()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category.name')->label('Category')->badge()->color('gray')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice.invoice_number')->label('Invoice #')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice.invoice_date')->label('Invoice Date')->date('M j, Y')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ship_date')->label('Ship Date')->date('M j, Y')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pushed_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(60)->color('danger')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('notes')->limit(50)->toggleable(isToggledHiddenByDefault: true)->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filtersFormColumns(3)
            ->filters([
                // Same free-text panel as All Shipments / All Charges. Most fields are direct columns on
                // the ledger; address/city/state/zip/service resolve by tracking (see ShipmentFilters).
                ShipmentFilters::text('job', 'Job (exact)', fn (Builder $q, string $v): Builder => $q->where('pace_job', $v)),
                ShipmentFilters::text('customer', 'Customer ID (exact)', fn (Builder $q, string $v): Builder => $q->where('pace_customer_id', $v)),
                ShipmentFilters::text('tracking', 'Tracking #', fn (Builder $q, string $v): Builder => $q->where('tracking_number', 'like', "%{$v}%")),
                ShipmentFilters::text('invoice_number', 'Invoice #', fn (Builder $q, string $v): Builder => $q->whereHas('invoice', fn (Builder $iq): Builder => $iq->where('invoice_number', 'like', "%{$v}%"))),
                ShipmentFilters::text('activity', 'Activity code', fn (Builder $q, string $v): Builder => $q->where('activity_code', 'like', "%{$v}%")),
                ShipmentFilters::text('jobcost', 'JobCost ID', fn (Builder $q, string $v): Builder => $q->where('pace_jobcost_id', 'like', "%{$v}%")),
                ShipmentFilters::text('reference1', 'Reference 1', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carton_costs', 'U_reference', $v)),
                ShipmentFilters::text('reference2', 'Reference 2', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carton_costs', 'U_reference2', $v)),
                ShipmentFilters::text('address', 'Address', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carrier_invoice_lines', 'original_address_1', $v)),
                ShipmentFilters::text('city', 'City', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carrier_invoice_lines', 'original_city', $v)),
                ShipmentFilters::text('state', 'State', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carrier_invoice_lines', 'original_state', $v)),
                ShipmentFilters::text('zip', 'Zip', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carrier_shipments', 'zip', $v)),
                ShipmentFilters::text('service', 'Service', fn (Builder $q, string $v): Builder => ShipmentFilters::trackingMatch($q, 'chargeback_pushes', 'carrier_shipments', 'service', $v)),
                SelectFilter::make('carrier_id')->label('Carrier')->relationship('carrier', 'name'),
                SelectFilter::make('charge_category_id')->label('Category')->relationship('category', 'name'),
                SelectFilter::make('driver')->label('Chargeback Code')
                    ->options(fn (): array => ChargebackPush::query()->whereNotNull('driver')->distinct()->orderBy('driver')->pluck('driver', 'driver')->all()),
                Filter::make('orphaned')
                    ->label('Orphaned line (backing charge deleted on re-import)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('carrier_charge_id')),
                ChargebackPushTable::viewFilter(),
                DateRangeFilter::make('ship_date', 'Ship date'),
                DateRangeFilter::make('pushed_at', 'Pushed date'),
            ], layout: FiltersLayout::Modal)
            ->recordActions(ChargebackPushTable::reviewActions())
            // Export is the global Import/Export (2-arrows) button, which already streams only the
            // filtered query — no separate whole-table "Export CSV" here.
            ->defaultSort('id', 'desc')
            ->paginated([50, 100, 'all']);
    }
}
