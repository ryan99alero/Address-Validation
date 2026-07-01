<?php

namespace App\Filament\Resources\CarrierShipmentSummaries\Pages;

use App\Filament\Concerns\HasRebuildReportsAction;
use App\Filament\Resources\CarrierShipmentSummaries\CarrierShipmentSummaryResource;
use Filament\Resources\Pages\ListRecords;

class ListCarrierShipmentSummaries extends ListRecords
{
    use HasRebuildReportsAction;

    protected static string $resource = CarrierShipmentSummaryResource::class;
}
