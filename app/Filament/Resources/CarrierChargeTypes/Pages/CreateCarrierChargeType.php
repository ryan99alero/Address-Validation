<?php

namespace App\Filament\Resources\CarrierChargeTypes\Pages;

use App\Filament\Resources\CarrierChargeTypes\CarrierChargeTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarrierChargeType extends CreateRecord
{
    protected static string $resource = CarrierChargeTypeResource::class;

    protected function afterCreate(): void
    {
        $this->record->recategorizeAffectedCharges();
    }
}
