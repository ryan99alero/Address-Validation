<?php

namespace App\Filament\Resources\CarrierInvoices\Pages;

use App\Filament\Pages\ChargebackPushes;
use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Models\ChargebackPush;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCarrierInvoices extends ListRecords
{
    protected static string $resource = CarrierInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $needAttention = ChargebackPush::whereIn('status', [
            ChargebackPush::STATUS_FAILED,
            ChargebackPush::STATUS_UNVERIFIED,
        ])->count();

        return [
            Action::make('chargebackLedger')
                ->label($needAttention > 0 ? "Chargeback Ledger ({$needAttention} need attention)" : 'Chargeback Ledger')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color($needAttention > 0 ? 'danger' : 'gray')
                ->url(ChargebackPushes::getUrl()),
            CreateAction::make(),
        ];
    }
}
