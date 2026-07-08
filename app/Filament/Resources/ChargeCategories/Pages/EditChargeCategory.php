<?php

namespace App\Filament\Resources\ChargeCategories\Pages;

use App\Filament\Resources\ChargeCategories\ChargeCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditChargeCategory extends EditRecord
{
    protected static string $resource = ChargeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
