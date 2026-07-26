<?php

namespace App\Filament\Resources\ChargeCategories\Pages;

use App\Filament\Resources\ChargeCategories\ChargeCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChargeCategories extends ListRecords
{
    protected static string $resource = ChargeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
