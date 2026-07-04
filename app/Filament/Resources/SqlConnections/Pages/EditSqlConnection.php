<?php

namespace App\Filament\Resources\SqlConnections\Pages;

use App\Filament\Resources\SqlConnections\SqlConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSqlConnection extends EditRecord
{
    protected static string $resource = SqlConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
