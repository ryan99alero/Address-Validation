<?php

namespace App\Filament\Resources\PaceCorrections;

use App\Filament\Resources\PaceCorrections\Pages\ListPaceCorrections;
use App\Filament\Resources\PaceCorrections\Tables\PaceCorrectionsTable;
use App\Models\SystemLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaceCorrectionResource extends Resource
{
    protected static ?string $model = SystemLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = 'Pace Address Corrections';

    protected static ?string $modelLabel = 'Pace Address Correction';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'pace_address_correction');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PaceCorrectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaceCorrections::route('/'),
        ];
    }
}
