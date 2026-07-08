<?php

namespace App\Filament\Resources\CarrierCharges\Pages;

use App\Filament\Pages\CarrierChargeCatalog;
use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListCarrierCharges extends ListRecords
{
    protected static string $resource = CarrierChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('catalog')
                ->label('Charge Catalog')
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
        return [
            'all' => Tab::make('All'),
            'uncategorized' => Tab::make('Uncategorized')
                ->icon(Heroicon::OutlinedQuestionMarkCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('charge_category_id')),
        ];
    }
}
