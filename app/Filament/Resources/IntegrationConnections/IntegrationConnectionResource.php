<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\IntegrationConnections\Pages\CreateIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\EditIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\ListIntegrationConnections;
use App\Filament\Resources\IntegrationConnections\RelationManagers\ObjectsRelationManager;
use App\Filament\Resources\IntegrationConnections\Schemas\IntegrationConnectionForm;
use App\Filament\Resources\IntegrationConnections\Tables\IntegrationConnectionsTable;
use App\Models\IntegrationConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class IntegrationConnectionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = IntegrationConnection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'ERP & Pace Connections';

    protected static ?string $modelLabel = 'Integration Connection';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return IntegrationConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IntegrationConnectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ObjectsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationConnections::route('/'),
            'create' => CreateIntegrationConnection::route('/create'),
            'edit' => EditIntegrationConnection::route('/{record}/edit'),
        ];
    }
}
