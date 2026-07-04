<?php

namespace App\Filament\Resources\SqlConnections\Pages;

use App\Filament\Resources\SqlConnections\SqlConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSqlConnections extends ListRecords
{
    protected static string $resource = SqlConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
