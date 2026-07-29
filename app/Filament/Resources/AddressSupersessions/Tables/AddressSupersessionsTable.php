<?php

namespace App\Filament\Resources\AddressSupersessions\Tables;

use App\Models\AddressSupersession;
use App\Models\Carrier;
use App\Services\Invoices\CorrectionThreader;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class AddressSupersessionsTable
{
    private const STATUS_COLORS = [
        'applied' => 'success',
        'pending_review' => 'warning',
        'rejected_garbage' => 'danger',
        'dismissed' => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from')
                    ->label('Was (good) →')
                    ->state(fn (AddressSupersession $record): string => self::formatSnapshot($record->old_snapshot))
                    ->color('danger')
                    ->wrap()
                    // Global search box matches the denormalized index: tracking, invoice #, Pace
                    // job/customer, and either correction's addresses.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('search_text', 'like', '%'.mb_strtolower(trim($search)).'%')),
                TextColumn::make('to')
                    ->label('Carrier corrected to')
                    ->state(fn (AddressSupersession $record): string => self::formatSnapshot($record->new_snapshot))
                    ->color('success')
                    ->wrap(),
                TextColumn::make('reason')
                    ->label('Why')
                    ->state(function (AddressSupersession $record): string {
                        $reason = $record->guard_result['reason'] ?? '—';
                        $miles = $record->guard_result['distance_miles'] ?? null;

                        return $miles !== null ? $reason.' · '.$miles.'mi' : (string) $reason;
                    })
                    ->badge()
                    ->color('gray'),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('trigger')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state)),
                TextColumn::make('detected_at')
                    ->label('Detected')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('detected_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending_review' => 'Pending review',
                        'applied' => 'Applied',
                        'rejected_garbage' => 'Rejected (garbage)',
                        'dismissed' => 'Dismissed',
                    ])
                    ->default('pending_review'),
                SelectFilter::make('trigger')
                    ->options([
                        'recorrection' => 'Re-correction',
                        'variant_conflict' => 'Variant conflict',
                        'reverify_drift' => 'Reverify drift',
                        'backfill' => 'Backfill',
                        'manual' => 'Manual',
                    ]),
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->searchPlaceholder('Tracking, job #, invoice, or address…')
            ->recordActions([
                Action::make('apply')
                    ->label('Apply')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (AddressSupersession $record): bool => $record->status === AddressSupersession::STATUS_PENDING_REVIEW && (Auth::user()?->isAdmin() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Supersede the old form with the carrier-corrected one and re-point its bad addresses.')
                    ->action(function (AddressSupersession $record): void {
                        abort_unless(Auth::user()?->isAdmin() ?? false, 403);
                        $ok = app(CorrectionThreader::class)->applyPending($record, Auth::id());
                        Notification::make()
                            ->title($ok ? 'Correction applied' : 'Could not apply (address missing or already resolved)')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (AddressSupersession $record): bool => $record->status === AddressSupersession::STATUS_PENDING_REVIEW && (Auth::user()?->isAdmin() ?? false))
                    ->requiresConfirmation()
                    ->action(function (AddressSupersession $record): void {
                        abort_unless(Auth::user()?->isAdmin() ?? false, 403);
                        $record->update(['status' => AddressSupersession::STATUS_DISMISSED]);
                        Notification::make()->title('Dismissed')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('applySelected')
                        ->label('Apply selected')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false)
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            abort_unless(Auth::user()?->isAdmin() ?? false, 403);
                            $threader = app(CorrectionThreader::class);
                            $applied = 0;
                            foreach ($records as $record) {
                                if ($record->status === AddressSupersession::STATUS_PENDING_REVIEW && $threader->applyPending($record, Auth::id())) {
                                    $applied++;
                                }
                            }
                            Notification::make()->title("Applied {$applied} correction(s)")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('dismissSelected')
                        ->label('Dismiss selected')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false)
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            abort_unless(Auth::user()?->isAdmin() ?? false, 403);
                            $ids = $records->where('status', AddressSupersession::STATUS_PENDING_REVIEW)->pluck('id');
                            AddressSupersession::whereIn('id', $ids)->update(['status' => AddressSupersession::STATUS_DISMISSED]);
                            Notification::make()->title('Dismissed '.$ids->count().' event(s)')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private static function formatSnapshot(?array $snapshot): string
    {
        if ($snapshot === null) {
            return '—';
        }

        $zip = ($snapshot['postal'] ?? '').(($snapshot['postal_ext'] ?? null) ? '-'.$snapshot['postal_ext'] : '');

        return trim(implode('  ', array_filter([
            $snapshot['address_1'] ?? null,
            trim(implode(' ', array_filter([$snapshot['city'] ?? null, $snapshot['state'] ?? null, $zip]))),
        ]))) ?: '—';
    }
}
