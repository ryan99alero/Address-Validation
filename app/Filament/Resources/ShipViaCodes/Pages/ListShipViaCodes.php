<?php

namespace App\Filament\Resources\ShipViaCodes\Pages;

use App\Filament\Resources\ShipViaCodes\ShipViaCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShipViaCodes extends ListRecords
{
    protected static string $resource = ShipViaCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
