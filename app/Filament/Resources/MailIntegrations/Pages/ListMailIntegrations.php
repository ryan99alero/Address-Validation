<?php

namespace App\Filament\Resources\MailIntegrations\Pages;

use App\Filament\Resources\MailIntegrations\MailIntegrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMailIntegrations extends ListRecords
{
    protected static string $resource = MailIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
