<?php

namespace App\Filament\Resources\Addresses\RelationManagers;

use App\Filament\Resources\Addresses\AddressResource;
use App\Models\AddressCandidate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'candidates';

    protected static ?string $title = 'Choose Correction';

    /**
     * Only show the picker when there are candidates to choose between.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->candidates()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Choose Correction')
            ->description('Select which corrected address to keep. The other will be discarded.')
            ->paginated(false)
            ->columns([
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (AddressCandidate $record): string => match ($record->source) {
                        AddressCandidate::SOURCE_INVOICE_DB => 'success',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (AddressCandidate $record): string => $record->source_label),
                TextColumn::make('full_address')
                    ->label('Corrected Address')
                    ->state(fn (AddressCandidate $record): string => $record->full_address)
                    ->wrap(),
                TextColumn::make('classification')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'Unknown')),
                TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->formatStateUsing(fn ($state): string => $state !== null ? number_format((float) $state).'%' : '-'),
            ])
            ->recordActions([
                Action::make('use')
                    ->label('Use this address')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Use this corrected address?')
                    ->modalDescription('This becomes the address and the other candidate is discarded.')
                    ->action(function (AddressCandidate $record) {
                        $addressId = $record->address_id;
                        $record->choose();

                        Notification::make()
                            ->title('Address updated')
                            ->body('The selected correction was applied.')
                            ->success()
                            ->send();

                        return redirect(AddressResource::getUrl('view', ['record' => $addressId]));
                    }),
            ]);
    }
}
