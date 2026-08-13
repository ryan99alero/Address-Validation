<?php

namespace App\Filament\Resources\CarrierShipmentSummaries;

use App\Filament\Resources\CarrierShipmentSummaries\Pages\ListCarrierShipmentSummaries;
use App\Filament\Resources\CarrierShipmentSummaries\Pages\ViewCarrierShipmentSummary;
use App\Filament\Resources\CarrierShipmentSummaries\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\CarrierShipmentSummaries\Tables\CarrierShipmentSummariesTable;
use App\Models\CarrierShipment;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CarrierShipmentSummaryResource extends Resource
{
    protected static ?string $model = CarrierShipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Costs';

    protected static ?string $navigationLabel = 'All Shipments';

    protected static ?string $modelLabel = 'Shipment';

    protected static ?int $navigationSort = 3;

    // The cross-invoice, all-carriers shipment view (the per-invoice "Per-Shipment Costs" tab shows
    // only a single invoice's shipments). Surfaced in the Carrier Costs group as "All Shipments".
    protected static bool $shouldRegisterNavigation = true;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager-load the relationships the table/view columns read (carton is a non-FK hasOne by
        // tracking number) so the extra fields don't N+1 across the page.
        return parent::getEloquentQuery()->with(['carrier', 'invoice', 'carton']);
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
                    TextEntry::make('ship_date')->label('Ship Date')->date('M j, Y')->placeholder('—'),
                    TextEntry::make('tracking_number')->label('Tracking #')->copyable(),
                    TextEntry::make('carrier.name')->label('Carrier')->badge(),
                    TextEntry::make('service')->label('Service')->badge()->placeholder('—'),
                    TextEntry::make('base_amount')->label('Base / Initial')->money('USD'),
                    TextEntry::make('fee_amount')->label('Fees')->money('USD'),
                    TextEntry::make('printed_total')->label('Total')->money('USD')->weight('bold'),
                    TextEntry::make('fee_abbrevs')->label('Fees applied')->placeholder('—'),
                ]),
            Section::make('Pace / Invoice')
                ->columns(4)
                ->schema([
                    TextEntry::make('invoice.invoice_number')->label('Invoice #')->placeholder('—'),
                    TextEntry::make('carton.pace_job_number')->label('Job #')->placeholder('— (not in Pace / not invoiced)'),
                    TextEntry::make('carton.pace_customer_id')->label('Customer')->placeholder('—'),
                    TextEntry::make('carton.pace_customer_name')->label('Customer Name')->placeholder('—'),
                    TextEntry::make('carton.U_reference')->label('Reference 1')->placeholder('—'),
                    TextEntry::make('carton.U_reference2')->label('Reference 2')->placeholder('—'),
                    TextEntry::make('carton.U_reference3')->label('Reference 3')->placeholder('—'),
                    TextEntry::make('is_third_party')->label('Billing')->badge()
                        ->formatStateUsing(fn ($state): string => $state ? '3rd Party' : 'On Account')
                        ->color(fn ($state): string => $state ? 'warning' : 'gray'),
                ]),
            Section::make('Package / Routing')
                ->columns(4)
                ->schema([
                    TextEntry::make('zip')->label('Zip')->placeholder('—'),
                    TextEntry::make('zone')->label('Zone')->placeholder('—'),
                    TextEntry::make('weight')->label('Weight')->placeholder('—'),
                    TextEntry::make('billed_weight')->label('Billed Wt')->placeholder('—'),
                    TextEntry::make('section')->label('Section')->badge()->color('gray')->placeholder('—'),
                    TextEntry::make('source_type')->label('Source')->badge()->color('gray')->placeholder('—'),
                    TextEntry::make('sender')->label('Sender')->placeholder('—')->columnSpan(2),
                    TextEntry::make('receiver')->label('Receiver')->placeholder('—')->columnSpan(2),
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
