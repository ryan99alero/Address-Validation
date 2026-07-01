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
