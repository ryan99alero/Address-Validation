<?php

namespace App\Filament\Resources\AddressSupersessions\Tables;

use App\Models\AddressSupersession;
use App\Models\Carrier;
use App\Services\Invoices\CorrectionThreader;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                    ->label('Was (shipped / current good)')
                    ->state(fn (AddressSupersession $record): string => self::fieldsToHtml($record->wasFields()))
                    ->html()
                    ->color('danger')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->action(self::detailsAction())
                    // Global search box matches the denormalized index: tracking, invoice #, Pace
                    // job/customer, company/contact, and either correction's addresses.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('search_text', 'like', '%'.mb_strtolower(trim($search)).'%')),
                TextColumn::make('to')
                    ->label('Corrected to')
                    ->state(fn (AddressSupersession $record): string => self::fieldsToHtml($record->correctedFields())
                        .($record->isManuallyEdited() ? '<br><span class="text-xs">✎ manually edited</span>' : ''))
                    ->html()
                    ->color(fn (AddressSupersession $record): string => $record->isManuallyEdited() ? 'info' : 'success')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->action(self::detailsAction()),
                TextColumn::make('loop')
                    ->label('Loop')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (AddressSupersession $record): ?string => self::isReversal($record) ? '↔ Reversal' : null)
                    ->color('warning')
                    ->icon(fn (AddressSupersession $record): ?string => self::isReversal($record) ? 'heroicon-m-arrow-path' : null)
                    ->tooltip('A↔B thrash: this address pair was also corrected in the opposite direction — the carrier (or a later invoice) keeps flipping it back. Worth a manual look.'),
                TextColumn::make('tracking')
                    ->label('Tracking')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('pace_job')
                    ->label('Job #')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('customer')
                    ->label('Customer')
                    ->state(function (AddressSupersession $record): ?string {
                        $id = $record->pace_customer_id;
                        $name = $record->pace_customer_name;

                        return trim(($id ?? '').($id !== null && $name !== null ? ' · ' : '').($name ?? '')) ?: null;
                    })
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
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
                TextColumn::make('reference_date')
                    ->label('Shipment / Invoice Date')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable()
                    ->tooltip('Ship date of the correction (falls back to the invoice date) — not the processing date'),
            ])
            ->defaultSort('reference_date', 'desc')
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
                Filter::make('reversal')
                    ->label('Reversal loops (A↔B) only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('old_corrected_address_id')
                        ->whereNotNull('new_corrected_address_id')
                        ->whereExists(fn ($q) => $q->from('address_supersessions as r')
                            ->whereColumn('r.old_corrected_address_id', 'address_supersessions.new_corrected_address_id')
                            ->whereColumn('r.new_corrected_address_id', 'address_supersessions.old_corrected_address_id'))),
                TernaryFilter::make('age')
                    ->label('Age')
                    ->placeholder('All ages')
                    ->trueLabel('Within the last year')
                    ->falseLabel('Older than a year')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->where('reference_date', '>=', now()->subYear()->toDateString()),
                        false: fn (Builder $q): Builder => $q->where('reference_date', '<', now()->subYear()->toDateString()),
                        blank: fn (Builder $q): Builder => $q,
                    ),
                TernaryFilter::make('manually_edited')
                    ->label('Manually edited')
                    ->placeholder('All')
                    ->trueLabel('Manually edited only')
                    ->falseLabel('Not edited')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('corrected_edited_at'),
                        false: fn (Builder $q): Builder => $q->whereNull('corrected_edited_at'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->searchPlaceholder('Tracking, job #, invoice, or address…')
            ->recordActions([
                self::detailsAction(),
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
     * Structured address as its own lines — Business Name, Contact, Address 1, Address 2/suite, then
     * City, State ZIP — so a suite-add correction is visible instead of collapsing to one blob.
     *
     * @param  array{company: ?string, name: ?string, address_1: ?string, address_2: ?string, city: ?string, state: ?string, postal: ?string, postal_ext: ?string}  $f
     */
    /**
     * A reversal loop: this event (old → new) has a mirror event (new → old) — the carrier or a later
     * invoice corrected the same pair back the other way (A↔B thrash).
     */
    private static function isReversal(AddressSupersession $record): bool
    {
        if ($record->old_corrected_address_id === null || $record->new_corrected_address_id === null) {
            return false;
        }

        return AddressSupersession::query()
            ->where('old_corrected_address_id', $record->new_corrected_address_id)
            ->where('new_corrected_address_id', $record->old_corrected_address_id)
            ->exists();
    }

    private static function fieldsToHtml(array $f): string
    {
        $zip = ($f['postal'] ?? '').(($f['postal_ext'] ?? null) ? '-'.$f['postal_ext'] : '');

        $lines = array_filter([
            $f['company'] ?? null,
            $f['name'] ?? null,
            $f['address_1'] ?? null,
            $f['address_2'] ?? null,
            trim(implode(' ', array_filter([$f['city'] ?? null, $f['state'] ?? null, $zip]))),
        ], fn ($line): bool => $line !== null && trim((string) $line) !== '');

        return $lines === [] ? '—' : implode('<br>', array_map(fn ($line): string => e($line), $lines));
    }

    /**
     * The click-through detail modal: labeled "Was" fields (read-only) and an editable "Corrected to".
     * Saving stores a corrected_override + marks the event manually edited; Apply then supersedes to
     * the edited address. Editing is admin-only; everyone can view.
     */
    private static function detailsAction(): Action
    {
        return Action::make('details')
            ->label('View / Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading('Re-Correction detail')
            ->modalSubmitActionLabel('Save corrected address')
            ->fillForm(fn (AddressSupersession $record): array => self::correctedFormState($record))
            ->schema([
                Section::make('Was (shipped / current good)')
                    ->description('The address we shipped to / currently hold as good.')
                    ->schema(self::readonlyFields())
                    ->columns(2),
                Section::make('Corrected to')
                    ->description('The address the engine will supersede to when applied. Edit to override the carrier.')
                    ->schema(self::editableFields())
                    ->columns(2),
            ])
            ->action(function (array $data, AddressSupersession $record): void {
                abort_unless(Auth::user()?->isAdmin() ?? false, 403);

                $record->update([
                    'corrected_override' => [
                        'company' => $data['company'] ?: null,
                        'name' => $data['name'] ?: null,
                        'address_1' => $data['address_1'] ?: null,
                        'address_2' => $data['address_2'] ?: null,
                        'city' => $data['city'] ?: null,
                        'state' => $data['state'] ?: null,
                        'postal' => $data['postal'] ?: null,
                    ],
                    'corrected_edited_at' => now(),
                    'corrected_edited_by' => Auth::id(),
                ]);
                $record->rebuildSearchText();

                Notification::make()->title('Corrected address saved (manually edited)')->success()->send();
            });
    }

    /**
     * @return array<int, Placeholder>
     */
    private static function readonlyFields(): array
    {
        $get = fn (string $key): callable => fn (AddressSupersession $record): string => (string) (self::wasFormState($record)[$key] ?? '') ?: '—';

        return [
            Placeholder::make('was_company')->label('Business Name')->content($get('company')),
            Placeholder::make('was_name')->label('Contact')->content($get('name')),
            Placeholder::make('was_address_1')->label('Address 1')->content($get('address_1')),
            Placeholder::make('was_address_2')->label('Address 2')->content($get('address_2')),
            Placeholder::make('was_city')->label('City')->content($get('city')),
            Placeholder::make('was_state')->label('State')->content($get('state')),
            Placeholder::make('was_postal')->label('ZIP')->content($get('postal')),
        ];
    }

    /**
     * @return array<int, TextInput>
     */
    private static function editableFields(): array
    {
        $editable = fn (): bool => Auth::user()?->isAdmin() ?? false;

        return [
            TextInput::make('company')->label('Business Name')->disabled(fn (): bool => ! $editable()),
            TextInput::make('name')->label('Contact')->disabled(fn (): bool => ! $editable()),
            TextInput::make('address_1')->label('Address 1')->disabled(fn (): bool => ! $editable()),
            TextInput::make('address_2')->label('Address 2')->disabled(fn (): bool => ! $editable()),
            TextInput::make('city')->label('City')->disabled(fn (): bool => ! $editable()),
            TextInput::make('state')->label('State')->maxLength(2)->disabled(fn (): bool => ! $editable()),
            TextInput::make('postal')->label('ZIP')->disabled(fn (): bool => ! $editable()),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private static function wasFormState(AddressSupersession $record): array
    {
        $f = $record->wasFields();

        return ['company' => $f['company'], 'name' => $f['name'], 'address_1' => $f['address_1'],
            'address_2' => $f['address_2'], 'city' => $f['city'], 'state' => $f['state'],
            'postal' => ($f['postal'] ?? '').(($f['postal_ext'] ?? null) ? '-'.$f['postal_ext'] : '')];
    }

    /**
     * @return array<string, ?string>
     */
    private static function correctedFormState(AddressSupersession $record): array
    {
        $f = $record->correctedFields();

        return ['company' => $f['company'], 'name' => $f['name'], 'address_1' => $f['address_1'],
            'address_2' => $f['address_2'], 'city' => $f['city'], 'state' => $f['state'],
            'postal' => ($f['postal'] ?? '').(($f['postal_ext'] ?? null) ? '-'.$f['postal_ext'] : '')];
    }
}
