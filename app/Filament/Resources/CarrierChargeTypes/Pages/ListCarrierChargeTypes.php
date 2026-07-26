<?php

namespace App\Filament\Resources\CarrierChargeTypes\Pages;

use App\Filament\Pages\CarrierChargeCatalog;
use App\Filament\Resources\CarrierChargeTypes\CarrierChargeTypeResource;
use App\Models\Carrier;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListCarrierChargeTypes extends ListRecords
{
    protected static string $resource = CarrierChargeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('catalog')
                ->label('Review unmapped charges')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(CarrierChargeCatalog::getUrl()),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $ids = Carrier::query()->pluck('id', 'slug');

        return [
            'all' => Tab::make('All'),
            'ups' => Tab::make('UPS')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('carrier_id', $ids['ups'] ?? 0)),
            'fedex' => Tab::make('FedEx')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('carrier_id', $ids['fedex'] ?? 0)),
            'generic' => Tab::make('Generic')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->whereNull('carrier_id')),
        ];
    }
}
