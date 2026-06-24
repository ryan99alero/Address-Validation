<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationConnections extends ListRecords
{
    protected static string $resource = IntegrationConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
