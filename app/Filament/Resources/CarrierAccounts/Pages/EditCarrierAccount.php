<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarrierAccount extends EditRecord
{
    protected static string $resource = CarrierAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
