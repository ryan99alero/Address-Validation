<?php

namespace App\Filament\Resources\ExportTemplates\Pages;

use App\Filament\Resources\ExportTemplates\ExportTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExportTemplates extends ListRecords
{
    protected static string $resource = ExportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
