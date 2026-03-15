<?php

namespace App\Filament\Resources\Addresses\Pages;

use App\Filament\Resources\Addresses\AddressResource;
use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;
use App\Services\AddressValidationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ViewAddress extends ViewRecord
{
    protected static string $resource = AddressResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Original Address')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Recipient Name'),
                        TextEntry::make('company')
                            ->label('Company'),
                        TextEntry::make('address_line_1')
                            ->label('Address Line 1')
                            ->columnSpanFull(),
                        TextEntry::make('address_line_2')
                            ->label('Address Line 2')
                            ->columnSpanFull()
                            ->hidden(fn (Address $record): bool => empty($record->address_line_2)),
                        TextEntry::make('city'),
                        TextEntry::make('state'),
                        TextEntry::make('postal_code')
                            ->label('ZIP/Postal Code'),
                        TextEntry::make('country_code')
                            ->label('Country'),
                        TextEntry::make('external_reference')
                            ->label('External Reference')
                            ->hidden(fn (Address $record): bool => empty($record->external_reference)),
                        TextEntry::make('source')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'import' => 'info',
                                'manual' => 'success',
                                'api' => 'warning',
                                default => 'gray',
                            }),
                    ]),
                Section::make('Validation Result')
                    ->columns(2)
                    ->visible(fn (Address $record): bool => $record->latestCorrection !== null)
                    ->schema([
                        TextEntry::make('latestCorrection.validation_status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                AddressCorrection::STATUS_VALID => 'success',
                                AddressCorrection::STATUS_INVALID => 'danger',
                                AddressCorrection::STATUS_AMBIGUOUS => 'warning',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                AddressCorrection::STATUS_VALID => 'Valid',
                                AddressCorrection::STATUS_INVALID => 'Invalid',
                                AddressCorrection::STATUS_AMBIGUOUS => 'Ambiguous',
                                default => 'Unknown',
                            }),
                        TextEntry::make('latestCorrection.carrier.name')
                            ->label('Validated By'),
                        TextEntry::make('latestCorrection.corrected_address_line_1')
                            ->label('Corrected Address Line 1')
                            ->columnSpanFull(),
                        TextEntry::make('latestCorrection.corrected_address_line_2')
                            ->label('Corrected Address Line 2')
                            ->columnSpanFull()
                            ->hidden(fn (Address $record): bool => empty($record->latestCorrection?->corrected_address_line_2)),
                        TextEntry::make('latestCorrection.corrected_city')
                            ->label('Corrected City'),
                        TextEntry::make('latestCorrection.corrected_state')
                            ->label('Corrected State'),
                        TextEntry::make('latestCorrection.corrected_postal_code')
                            ->label('Corrected ZIP')
                            ->formatStateUsing(fn (Address $record): string => $record->latestCorrection?->getFullPostalCode() ?? ''),
                        TextEntry::make('latestCorrection.corrected_country_code')
                            ->label('Corrected Country'),
                        TextEntry::make('latestCorrection.classification')
                            ->label('Classification')
                            ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'Unknown')),
                        TextEntry::make('latestCorrection.is_residential')
                            ->label('Residential')
                            ->badge()
                            ->color(fn (?bool $state): string => match ($state) {
                                true => 'info',
                                false => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (?bool $state): string => match ($state) {
                                true => 'Yes',
                                false => 'No',
                                default => 'Unknown',
                            }),
                        TextEntry::make('latestCorrection.confidence_score')
                            ->label('Confidence')
                            ->formatStateUsing(fn ($state): string => $state ? number_format($state * 100).'%' : 'N/A'),
                        TextEntry::make('latestCorrection.validated_at')
                            ->label('Validated At')
                            ->dateTime(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validate')
                ->label('Validate')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('primary')
                ->form([
                    Select::make('carrier_id')
                        ->label('Carrier')
                        ->options(Carrier::where('is_active', true)->pluck('name', 'id'))
                        ->required()
                        ->default(Carrier::where('is_active', true)->first()?->id),
                ])
                ->action(function (array $data): void {
                    $carrier = Carrier::find($data['carrier_id']);

                    if (! $carrier) {
                        Notification::make()
                            ->title('Error')
                            ->body('Carrier not found.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $validationService = app(AddressValidationService::class);
                        $correction = $validationService->validateAddress($this->record, $carrier->slug);

                        $this->record->refresh();

                        Notification::make()
                            ->title('Validation Complete')
                            ->body('Address has been validated. Status: '.ucfirst($correction->validation_status))
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Validation Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            EditAction::make(),
        ];
    }
}
