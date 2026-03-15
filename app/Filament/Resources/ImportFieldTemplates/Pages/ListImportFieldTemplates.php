<?php

namespace App\Filament\Resources\ImportFieldTemplates\Pages;

use App\Filament\Resources\ImportFieldTemplates\ImportFieldTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportFieldTemplates extends ListRecords
{
    protected static string $resource = ImportFieldTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
