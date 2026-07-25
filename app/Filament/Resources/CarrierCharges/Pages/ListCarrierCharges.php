<?php

namespace App\Filament\Resources\CarrierCharges\Pages;

use App\Filament\Concerns\ScopedTableSearch;
use App\Filament\Pages\CarrierChargeCatalog;
use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListCarrierCharges extends ListRecords
{
    use ScopedTableSearch;

    protected static string $resource = CarrierChargeResource::class;

    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        return $this->applyScopedColumnSearch($query) ?? parent::applyGlobalSearchToTableQuery($query);
    }

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
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('charge_category_id')),
        ];
    }
}
