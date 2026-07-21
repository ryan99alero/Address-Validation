<?php

namespace App\Filament\Support;

use App\Jobs\PushChargeback;
use App\Models\ChargebackPush;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shared chargeback-ledger table pieces so the global Chargeback Pushes page and the per-invoice
 * relation manager expose the SAME duplicate/needs-review filtering and handling. The two dispositions
 * that need a human — a cross-import Duplicate (reversal_state) and a near-duplicate held for review
 * (status quarantined) — are selectable from one "View" dropdown instead of separate toggles.
 */
class ChargebackPushTable
{
    /**
     * One dropdown covering every disposition: the real statuses (incl. quarantined = "needs review")
     * plus a synthetic "Duplicate — needs reversal" that filters on reversal_state.
     */
    public static function viewFilter(): SelectFilter
    {
        return SelectFilter::make('view')
            ->label('View')
            ->options(fn (): array => array_merge(
                ChargebackPush::query()->distinct()->orderBy('status')->pluck('status', 'status')->all(),
                [ChargebackPush::REVERSAL_NEEDS => 'Duplicate — needs reversal'],
            ))
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['value'] ?? null,
                fn (Builder $q, string $value): Builder => $value === ChargebackPush::REVERSAL_NEEDS
                    ? $q->where('reversal_state', ChargebackPush::REVERSAL_NEEDS)
                    : $q->where('status', $value),
            ));
    }

    /**
     * The Duplicate (reversal) + near-duplicate Conflict flag columns.
     *
     * @return array<int, TextColumn>
     */
    public static function flagColumns(): array
    {
        return [
            TextColumn::make('reversal_state')->label('Duplicate')->badge()->color('danger')->placeholder('—')
                ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                    ChargebackPush::REVERSAL_NEEDS => 'Needs reversal',
                    ChargebackPush::REVERSAL_PENDING => 'Reversing…',
                    ChargebackPush::REVERSAL_FAILED => 'Reversal failed',
                    default => null,
                })
                ->tooltip(fn (ChargebackPush $record): ?string => $record->duplicate_of_id ? 'Duplicate of cb#'.$record->duplicate_of_id : null),
            TextColumn::make('conflict_reason')->label('Conflict')->badge()->color('warning')->placeholder('—')
                ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                    ChargebackPush::CONFLICT_AMOUNT => 'Amount changed',
                    ChargebackPush::CONFLICT_CATEGORY => 'Recategorized',
                    default => null,
                })
                ->tooltip(fn (ChargebackPush $record): ?string => $record->conflict_with_id ? 'Conflicts with posted cb#'.$record->conflict_with_id : null),
        ];
    }

    /**
     * Per-row handling. Quarantined near-dupes get Push-anyway / Dismiss; flagged Duplicates get
     * Mark-reversed (backed out in Pace by hand) / Not-a-duplicate (false positive). Each stamps the
     * reviewer so the decision is auditable.
     *
     * @return array<int, Action>
     */
    public static function reviewActions(): array
    {
        return [
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
                    PushChargeback::dispatch(self::chargeFromLedger($record), force: true);
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
            Action::make('mark_reversed')
                ->label('Mark reversed')
                ->icon(Heroicon::OutlinedCheck)
                ->color('danger')
                ->visible(fn (ChargebackPush $record): bool => $record->reversal_state === ChargebackPush::REVERSAL_NEEDS)
                ->requiresConfirmation()
                ->modalHeading('Mark this duplicate as reversed?')
                ->modalDescription('Confirm you have backed the duplicate JobCost out of Pace (or that it can stay, e.g. Create Costs is off). It will no longer show as needing reversal.')
                ->action(function (ChargebackPush $record): void {
                    $record->update(['status' => ChargebackPush::STATUS_REVERSED, 'reversal_state' => null, 'reviewed_by_id' => Auth::id(), 'reviewed_at' => now()]);
                    Notification::make()->title('Marked reversed')->success()->send();
                }),
            Action::make('not_a_duplicate')
                ->label('Not a duplicate')
                ->icon(Heroicon::OutlinedFlag)
                ->color('gray')
                ->visible(fn (ChargebackPush $record): bool => $record->reversal_state === ChargebackPush::REVERSAL_NEEDS)
                ->schema([Textarea::make('review_note')->label('Reason')->required()->maxLength(500)])
                ->action(function (array $data, ChargebackPush $record): void {
                    $record->update(['reversal_state' => null, 'duplicate_of_id' => null, 'review_note' => $data['review_note'], 'reviewed_by_id' => Auth::id(), 'reviewed_at' => now()]);
                    Notification::make()->title('Cleared duplicate flag')->success()->send();
                }),
        ];
    }

    /**
     * Rebuild the charge-array a ledger row was claimed from, so a reviewer's "Push anyway" can force
     * it through the engine (bypassing the near-duplicate guard).
     *
     * @return array<string, mixed>
     */
    public static function chargeFromLedger(ChargebackPush $r): array
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
}
