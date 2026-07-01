<?php

namespace App\Filament\Resources\AddressCorrections\Pages;

use App\Filament\Resources\AddressCorrections\AddressCorrectionResource;
use App\Models\Carrier;
use App\Services\Invoices\CorrectionCachePurger;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAddressCorrections extends ListRecords
{
    protected static string $resource = AddressCorrectionResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('purgeCache')
                ->label('Purge Cache')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading("Purge a carrier's correction cache")
                ->modalDescription('Deletes cached address corrections that originated from the chosen carrier (keeping any still used by another carrier). Use this after deleting a carrier\'s invoices for a clean re-import — the cache rebuilds automatically as invoices are imported.')
                ->modalSubmitActionLabel('Purge')
                ->schema([
                    Select::make('carrier_id')
                        ->label('Carrier')
                        ->options(Carrier::orderBy('name')->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(CorrectionCachePurger::class)->purgeCarrier((int) $data['carrier_id']);

                    Notification::make()
                        ->title('Correction cache purged')
                        ->body("Deleted {$result['deleted']} corrections; kept {$result['kept']} still used by other carriers.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
