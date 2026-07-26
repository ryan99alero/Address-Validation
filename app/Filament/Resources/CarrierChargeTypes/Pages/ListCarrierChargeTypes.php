<?php

namespace App\Filament\Resources\CarrierChargeTypes\Pages;

use App\Filament\Concerns\HasApplyReclassificationAction;
use App\Filament\Pages\CarrierChargeCatalog;
use App\Filament\Resources\CarrierChargeTypes\CarrierChargeTypeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCarrierChargeTypes extends ListRecords
{
    use HasApplyReclassificationAction;

    protected static string $resource = CarrierChargeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->applyReclassificationAction(),
            CreateAction::make(),
            Action::make('catalog')
                ->label('Review unmapped charges')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(CarrierChargeCatalog::getUrl()),
        ];
    }
}
