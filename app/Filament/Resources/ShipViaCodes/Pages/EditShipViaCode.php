<?php

namespace App\Filament\Resources\ShipViaCodes\Pages;

use App\Filament\Resources\ShipViaCodes\ShipViaCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShipViaCode extends EditRecord
{
    protected static string $resource = ShipViaCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
