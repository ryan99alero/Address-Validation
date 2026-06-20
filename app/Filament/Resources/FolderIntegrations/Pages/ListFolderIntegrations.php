<?php

namespace App\Filament\Resources\FolderIntegrations\Pages;

use App\Filament\Resources\FolderIntegrations\FolderIntegrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFolderIntegrations extends ListRecords
{
    protected static string $resource = FolderIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
