<?php

namespace App\Filament\Resources\Addresses\Pages;

use App\Filament\Resources\Addresses\AddressResource;
use App\Models\Address;
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
                        TextEntry::make('input_name')
                            ->label('Recipient Name'),
                        TextEntry::make('input_company')
                            ->label('Company'),
                        TextEntry::make('input_address_1')
                            ->label('Address Line 1')
                            ->columnSpanFull(),
                        TextEntry::make('input_address_2')
                            ->label('Address Line 2')
                            ->columnSpanFull()
                            ->hidden(fn (Address $record): bool => empty($record->input_address_2)),
                        TextEntry::make('input_city')
                            ->label('City'),
                        TextEntry::make('input_state')
                            ->label('State'),
                        TextEntry::make('input_postal')
                            ->label('ZIP/Postal Code'),
                        TextEntry::make('input_country')
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
                    ->visible(fn (Address $record): bool => $record->validated_at !== null)
                    ->schema([
                        TextEntry::make('validation_status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'valid' => 'success',
                                'invalid' => 'danger',
                                'ambiguous' => 'warning',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'valid' => 'Valid',
                                'invalid' => 'Invalid',
                                'ambiguous' => 'Ambiguous',
                                'pending' => 'Pending',
                                default => 'Unknown',
                            }),
                        TextEntry::make('validatedByCarrier.name')
                            ->label('Validated By'),
                        TextEntry::make('output_address_1')
                            ->label('Corrected Address Line 1')
                            ->columnSpanFull(),
                        TextEntry::make('output_address_2')
                            ->label('Corrected Address Line 2')
                            ->columnSpanFull()
                            ->hidden(fn (Address $record): bool => empty($record->output_address_2)),
                        TextEntry::make('output_city')
                            ->label('Corrected City'),
                        TextEntry::make('output_state')
                            ->label('Corrected State'),
                        TextEntry::make('output_postal')
                            ->label('Corrected ZIP')
                            ->formatStateUsing(fn (Address $record): string => $record->getFullPostalCode() ?? ''),
                        TextEntry::make('output_country')
                            ->label('Corrected Country'),
                        TextEntry::make('classification')
                            ->label('Classification')
                            ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'Unknown')),
                        TextEntry::make('is_residential')
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
                        TextEntry::make('confidence_score')
                            ->label('Confidence')
                            ->formatStateUsing(fn ($state): string => $state ? number_format($state * 100).'%' : 'N/A'),
                        TextEntry::make('validated_at')
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
                        $result = $validationService->validateAddress($this->record, $carrier->slug);

                        $this->record->refresh();

                        Notification::make()
                            ->title('Validation Complete')
                            ->body('Address has been validated. Status: '.ucfirst($this->record->validation_status))
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
