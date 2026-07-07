<?php

namespace App\Filament\Resources\ChargeDrivers\Pages;

use App\Filament\Resources\ChargeDrivers\ChargeDriverResource;
use Filament\Resources\Pages\ListRecords;

class ListChargeDrivers extends ListRecords
{
    protected static string $resource = ChargeDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
