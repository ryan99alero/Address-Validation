<?php

namespace App\Filament\Resources\ChargeCategories;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ChargeCategories\Pages\EditChargeCategory;
use App\Filament\Resources\ChargeCategories\Pages\ListChargeCategories;
use App\Filament\Resources\ChargeCategories\Schemas\ChargeCategoryForm;
use App\Filament\Resources\ChargeCategories\Tables\ChargeCategoriesTable;
use App\Models\ChargeCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Fee Categories — the canonical "what kind of charge" catalog (Fuel, Base, DAS, Address
 * Correction…). Each category carries the Pace cost center the recoup push posts it to. Edit-only:
 * categories are seeded and referenced by name in code, so no create/delete/rename here.
 */
class ChargeCategoryResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ChargeCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Fee Categories';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ChargeCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChargeCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChargeCategories::route('/'),
            'edit' => EditChargeCategory::route('/{record}/edit'),
        ];
    }
}
