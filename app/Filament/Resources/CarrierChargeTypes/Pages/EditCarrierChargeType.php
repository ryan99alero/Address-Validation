<?php

namespace App\Filament\Resources\CarrierChargeTypes\Pages;

use App\Filament\Resources\CarrierChargeTypes\CarrierChargeTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarrierChargeType extends EditRecord
{
    protected static string $resource = CarrierChargeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recategorizeAffectedCharges();
    }
}
