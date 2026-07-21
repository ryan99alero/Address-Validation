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

    protected static string|\UnitEnum|null $navigationGroup = 'Address Intelligence';

    protected static ?string $navigationLabel = 'Import Batches';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

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
        $query = parent::getEloquentQuery();

        // Admins see every batch (the "Imported By" column shows who ran each); non-admins see only
        // their own imports. Previously ALL users were scoped to their own, so an admin couldn't see a
        // batch another user ran even though it appeared in exports.
        if (! auth()->user()?->is_admin) {
            $query->where('imported_by', auth()->id());
        }

        return $query;
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
