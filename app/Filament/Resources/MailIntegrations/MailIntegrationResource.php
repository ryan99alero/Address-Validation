<?php

namespace App\Filament\Resources\MailIntegrations;

use App\Filament\Clusters\Integrations\IntegrationsCluster;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\MailIntegrations\Pages\CreateMailIntegration;
use App\Filament\Resources\MailIntegrations\Pages\EditMailIntegration;
use App\Filament\Resources\MailIntegrations\Pages\ListMailIntegrations;
use App\Filament\Resources\MailIntegrations\Schemas\MailIntegrationForm;
use App\Filament\Resources\MailIntegrations\Tables\MailIntegrationsTable;
use App\Models\MailIntegration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MailIntegrationResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = MailIntegration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $cluster = IntegrationsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Views';

    protected static ?string $navigationLabel = 'Mail Integrations';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Mail Integration';

    public static function form(Schema $schema): Schema
    {
        return MailIntegrationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailIntegrationsTable::configure($table);
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
            'index' => ListMailIntegrations::route('/'),
            'create' => CreateMailIntegration::route('/create'),
            'edit' => EditMailIntegration::route('/{record}/edit'),
        ];
    }
}
