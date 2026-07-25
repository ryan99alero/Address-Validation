<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ScopedTableSearch;
use App\Filament\Support\CartonReferenceColumns;
use App\Filament\Support\ChargebackPushTable;
use App\Filament\Support\DateRangeFilter;
use App\Models\ChargebackPush;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
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
            ->query(ChargebackPush::query()->latest('id'))
            ->columns([
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'pushed' => 'success',
                        'failed', 'unverified' => 'danger',
                        'pending' => 'warning',
                        default => 'gray', // skipped_*
                    }),
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
                TextColumn::make('pushed_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(60)->color('danger')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('notes')->limit(50)->toggleable(isToggledHiddenByDefault: true)->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                ChargebackPushTable::viewFilter(),
                DateRangeFilter::make('ship_date', 'Ship date'),
            ])
            ->recordActions(ChargebackPushTable::reviewActions())
            // Export is the global Import/Export (2-arrows) button, which already streams only the
            // filtered query — no separate whole-table "Export CSV" here.
            ->defaultSort('id', 'desc')
            ->paginated([50, 100, 'all']);
    }
}
