<?php

namespace App\Filament\Resources\CarrierInvoices\Pages;

use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarrierInvoices extends ListRecords
{
    protected static string $resource = CarrierInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
