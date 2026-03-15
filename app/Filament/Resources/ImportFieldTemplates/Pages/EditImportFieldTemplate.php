<?php

namespace App\Filament\Resources\ImportFieldTemplates\Pages;

use App\Filament\Resources\ImportFieldTemplates\ImportFieldTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportFieldTemplate extends EditRecord
{
    protected static string $resource = ImportFieldTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
