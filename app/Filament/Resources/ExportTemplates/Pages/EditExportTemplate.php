<?php

namespace App\Filament\Resources\ExportTemplates\Pages;

use App\Filament\Resources\ExportTemplates\ExportTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExportTemplate extends EditRecord
{
    protected static string $resource = ExportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
