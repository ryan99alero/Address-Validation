<?php

namespace App\Filament\Resources\ImportFieldTemplates;

use App\Filament\Resources\ImportFieldTemplates\Pages\CreateImportFieldTemplate;
use App\Filament\Resources\ImportFieldTemplates\Pages\EditImportFieldTemplate;
use App\Filament\Resources\ImportFieldTemplates\Pages\ListImportFieldTemplates;
use App\Filament\Resources\ImportFieldTemplates\Schemas\ImportFieldTemplateForm;
use App\Filament\Resources\ImportFieldTemplates\Tables\ImportFieldTemplatesTable;
use App\Models\ImportFieldTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ImportFieldTemplateResource extends Resource
{
    protected static ?string $model = ImportFieldTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Templates';

    protected static ?string $navigationLabel = 'Import Templates';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ImportFieldTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportFieldTemplatesTable::configure($table);
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
            'index' => ListImportFieldTemplates::route('/'),
            'create' => CreateImportFieldTemplate::route('/create'),
            'edit' => EditImportFieldTemplate::route('/{record}/edit'),
        ];
    }
}
