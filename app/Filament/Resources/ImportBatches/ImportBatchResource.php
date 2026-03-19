<?php

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\EditImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Schemas\ImportBatchForm;
use App\Filament\Resources\ImportBatches\Tables\ImportBatchesTable;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static ?string $navigationLabel = 'Import Batches';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ImportBatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportBatchesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('imported_by', auth()->id());
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
            'index' => ListImportBatches::route('/'),
            'create' => CreateImportBatch::route('/create'),
            'edit' => EditImportBatch::route('/{record}/edit'),
        ];
    }
}
