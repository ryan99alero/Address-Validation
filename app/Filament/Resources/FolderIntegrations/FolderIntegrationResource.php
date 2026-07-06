<?php

namespace App\Filament\Resources\FolderIntegrations;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\FolderIntegrations\Pages\CreateFolderIntegration;
use App\Filament\Resources\FolderIntegrations\Pages\EditFolderIntegration;
use App\Filament\Resources\FolderIntegrations\Pages\ListFolderIntegrations;
use App\Filament\Resources\FolderIntegrations\Schemas\FolderIntegrationForm;
use App\Filament\Resources\FolderIntegrations\Tables\FolderIntegrationsTable;
use App\Models\FolderIntegration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FolderIntegrationResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = FolderIntegration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Folder Integrations';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Folder Integration';

    public static function form(Schema $schema): Schema
    {
        return FolderIntegrationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FolderIntegrationsTable::configure($table);
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
            'index' => ListFolderIntegrations::route('/'),
            'create' => CreateFolderIntegration::route('/create'),
            'edit' => EditFolderIntegration::route('/{record}/edit'),
        ];
    }
}
