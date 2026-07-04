<?php

namespace App\Filament\Resources\SqlConnections\Pages;

use App\Filament\Resources\SqlConnections\SqlConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSqlConnection extends CreateRecord
{
    protected static string $resource = SqlConnectionResource::class;
}
