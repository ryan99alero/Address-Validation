<?php

namespace App\Filament\Resources\SqlConnections;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\SqlConnections\Pages\CreateSqlConnection;
use App\Filament\Resources\SqlConnections\Pages\EditSqlConnection;
use App\Filament\Resources\SqlConnections\Pages\ListSqlConnections;
use App\Filament\Resources\SqlConnections\Schemas\SqlConnectionForm;
use App\Filament\Resources\SqlConnections\Tables\SqlConnectionsTable;
use App\Models\SqlConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SqlConnectionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SqlConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'SQL Connections';

    protected static ?string $modelLabel = 'SQL Connection';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return SqlConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SqlConnectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSqlConnections::route('/'),
            'create' => CreateSqlConnection::route('/create'),
            'edit' => EditSqlConnection::route('/{record}/edit'),
        ];
    }
}
