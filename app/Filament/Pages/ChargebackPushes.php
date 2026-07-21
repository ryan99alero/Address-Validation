<?php

namespace App\Filament\Pages;

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The chargeback ledger — every carrier charge pushed (or considered) as a Pace JobCost, with its
 * disposition and the returned JobCost id. Read-only view + CSV export (all fields incl. the notes
 * with the recorded→corrected address), so finance can trust it and chase failed/unverified rows.
 */
class ChargebackPushes extends Page implements HasTable
{
    use InteractsWithTable;

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
                // A charge a re-import forked into a second JobCost: flagged here as a duplicate that must
                // be backed out of Pace. The tooltip points at the canonical row it duplicates.
                TextColumn::make('reversal_state')->label('Duplicate')->badge()->color('danger')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        ChargebackPush::REVERSAL_NEEDS => 'Needs reversal',
                        ChargebackPush::REVERSAL_PENDING => 'Reversing…',
                        ChargebackPush::REVERSAL_FAILED => 'Reversal failed',
                        default => null,
                    })
                    ->tooltip(fn (ChargebackPush $record): ?string => $record->duplicate_of_id ? 'Duplicate of cb#'.$record->duplicate_of_id : null),
                // A near-duplicate held for review: same shipment as an already-posted charge, but its
                // amount or category changed on re-import. The tooltip points at the posted counterpart.
                TextColumn::make('conflict_reason')->label('Conflict')->badge()->color('warning')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        ChargebackPush::CONFLICT_AMOUNT => 'Amount changed',
                        ChargebackPush::CONFLICT_CATEGORY => 'Recategorized',
                        default => null,
                    })
                    ->tooltip(fn (ChargebackPush $record): ?string => $record->conflict_with_id ? 'Conflicts with posted cb#'.$record->conflict_with_id : null),
                TextColumn::make('pace_job')->label('Job')->searchable(),
                TextColumn::make('pace_customer_id')->label('Customer')->toggleable(),
                TextColumn::make('pace_jobcost_id')->label('JobCost ID')->fontFamily('mono')->placeholder('—')->searchable(),
                TextColumn::make('pushed_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(60)->color('danger')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('notes')->limit(50)->toggleable(isToggledHiddenByDefault: true)->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                SelectFilter::make('status')->options(fn (): array => ChargebackPush::query()->distinct()->pluck('status', 'status')->all()),
                Filter::make('needs_reversal')
                    ->label('Duplicates needing reversal')
                    ->query(fn (Builder $query): Builder => $query->where('reversal_state', ChargebackPush::REVERSAL_NEEDS))
                    ->toggle(),
                Filter::make('needs_review')
                    ->label('Needs review (near-duplicates)')
                    ->query(fn (Builder $query): Builder => $query->where('status', ChargebackPush::STATUS_QUARANTINED))
                    ->toggle(),
            ])
            ->recordActions([
                Action::make('push_anyway')
                    ->label('Push anyway')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->color('warning')
                    ->visible(fn (ChargebackPush $record): bool => $record->status === ChargebackPush::STATUS_QUARANTINED)
                    ->requiresConfirmation()
                    ->modalHeading('Push this near-duplicate to Pace?')
                    ->modalDescription('This posts a SECOND JobCost for the same shipment as an already-posted charge. Only do this if both charges are genuinely owed.')
                    ->action(function (ChargebackPush $record): void {
                        $record->update(['status' => ChargebackPush::STATUS_PENDING, 'reviewed_by_id' => Auth::id(), 'reviewed_at' => now()]);
                        PushChargeback::dispatch($this->chargeFromLedger($record), force: true);
                        Notification::make()->title('Queued for push (review override)')->success()->send();
                    }),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (ChargebackPush $record): bool => $record->status === ChargebackPush::STATUS_QUARANTINED)
                    ->schema([Textarea::make('review_note')->label('Reason')->required()->maxLength(500)])
                    ->action(function (array $data, ChargebackPush $record): void {
                        $record->update(['status' => ChargebackPush::STATUS_DISMISSED, 'review_note' => $data['review_note'], 'reviewed_by_id' => Auth::id(), 'reviewed_at' => now()]);
                        Notification::make()->title('Dismissed')->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([50, 100, 'all']);
    }

    /**
     * Rebuild the charge-array a quarantined ledger row was claimed from, so a reviewer's "Push anyway"
     * can force it through the engine (bypassing the near-duplicate guard).
     *
     * @return array<string, mixed>
     */
    private function chargeFromLedger(ChargebackPush $r): array
    {
        $invoice = $r->carrier_invoice_id
            ? DB::table('carrier_invoices')->where('id', $r->carrier_invoice_id)->first(['invoice_number', 'invoice_date'])
            : null;

        return [
            'carrier_charge_id' => $r->carrier_charge_id, 'carrier_id' => $r->carrier_id,
            'carrier_invoice_id' => $r->carrier_invoice_id, 'invoice_number' => $invoice->invoice_number ?? null,
            'invoice_date' => $invoice->invoice_date ?? null, 'tracking_number' => $r->tracking_number,
            'charge_category_id' => $r->charge_category_id, 'driver' => $r->driver, 'amount' => (float) $r->amount,
            'ship_date' => $r->ship_date?->format('Y-m-d'), 'activity_code' => $r->activity_code,
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $columns = ['id', 'txn_id', 'status', 'reversal_state', 'duplicate_of_id', 'carrier_id',
            'carrier_invoice_id', 'tracking_number', 'driver', 'charge_category_id', 'activity_code',
            'amount', 'ship_date', 'pace_job', 'pace_job_part', 'pace_customer_id', 'pace_jobcost_id',
            'pushed_at', 'attempts', 'last_error', 'notes'];

        return response()->streamDownload(function () use ($columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            ChargebackPush::query()->orderByDesc('id')->chunk(500, function ($rows) use ($out, $columns): void {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn (string $c) => (string) $row->{$c}, $columns));
                }
            });
            fclose($out);
        }, 'chargeback-pushes-'.now()->format('Ymd-His').'.csv');
    }
}
