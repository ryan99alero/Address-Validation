<?php

namespace App\Filament\Resources\ExportTemplates;

use App\Filament\Resources\ExportTemplates\Pages\CreateExportTemplate;
use App\Filament\Resources\ExportTemplates\Pages\EditExportTemplate;
use App\Filament\Resources\ExportTemplates\Pages\ListExportTemplates;
use App\Filament\Resources\ExportTemplates\Schemas\ExportTemplateForm;
use App\Filament\Resources\ExportTemplates\Tables\ExportTemplatesTable;
use App\Models\ExportTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExportTemplateResource extends Resource
{
    protected static ?string $model = ExportTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Templates';

    protected static ?string $navigationLabel = 'Export Templates';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ExportTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExportTemplatesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query) {
                $query->where('created_by', auth()->id())
                    ->orWhere('is_shared', true);
            });
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
            'index' => ListExportTemplates::route('/'),
            'create' => CreateExportTemplate::route('/create'),
            'edit' => EditExportTemplate::route('/{record}/edit'),
        ];
    }
}
