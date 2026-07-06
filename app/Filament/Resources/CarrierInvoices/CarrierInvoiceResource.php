<?php

namespace App\Filament\Resources\CarrierInvoices;

use App\Filament\Resources\CarrierInvoices\Pages\ListCarrierInvoices;
use App\Filament\Resources\CarrierInvoices\Pages\ViewCarrierInvoice;
use App\Filament\Resources\CarrierInvoices\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\CarrierInvoices\RelationManagers\CorrectionLinesRelationManager;
use App\Filament\Resources\CarrierInvoices\RelationManagers\SourceFilesRelationManager;
use App\Filament\Resources\CarrierInvoices\Schemas\CarrierInvoiceForm;
use App\Filament\Resources\CarrierInvoices\Tables\CarrierInvoicesTable;
use App\Models\CarrierInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CarrierInvoiceResource extends Resource
{
    protected static ?string $model = CarrierInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Invoices';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return CarrierInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarrierInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChargesRelationManager::class,
            CorrectionLinesRelationManager::class,
            SourceFilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarrierInvoices::route('/'),
            'view' => ViewCarrierInvoice::route('/{record}'),
        ];
    }
}
