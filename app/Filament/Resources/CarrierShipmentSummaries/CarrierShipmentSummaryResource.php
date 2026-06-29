<?php

namespace App\Filament\Resources\CarrierShipmentSummaries;

use App\Filament\Resources\CarrierShipmentSummaries\Pages\ListCarrierShipmentSummaries;
use App\Filament\Resources\CarrierShipmentSummaries\Pages\ViewCarrierShipmentSummary;
use App\Filament\Resources\CarrierShipmentSummaries\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\CarrierShipmentSummaries\Tables\CarrierShipmentSummariesTable;
use App\Models\CarrierShipmentSummary;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CarrierShipmentSummaryResource extends Resource
{
    protected static ?string $model = CarrierShipmentSummary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Invoices';

    protected static ?string $navigationLabel = 'Per-Shipment Costs';

    protected static ?string $modelLabel = 'Shipment';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CarrierShipmentSummariesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shipment')
                ->columns(4)
                ->schema([
                    TextEntry::make('invoice_date')->label('Invoice Date')->date('M j, Y'),
                    TextEntry::make('tracking_number')->label('Tracking #')->copyable(),
                    TextEntry::make('carrier.name')->label('Carrier')->badge(),
                    TextEntry::make('service')->label('Service')->badge()->placeholder('—'),
                    TextEntry::make('base_amount')->label('Base / Initial')->money('USD'),
                    TextEntry::make('fee_amount')->label('Fees')->money('USD'),
                    TextEntry::make('total_amount')->label('Total')->money('USD')->weight('bold'),
                    TextEntry::make('fee_abbrevs')->label('Fees applied')->placeholder('—'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            ChargesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarrierShipmentSummaries::route('/'),
            'view' => ViewCarrierShipmentSummary::route('/{record}'),
        ];
    }
}
