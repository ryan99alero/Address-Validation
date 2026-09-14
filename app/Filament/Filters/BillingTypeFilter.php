<?php

namespace App\Filament\Filters;

use App\Services\Invoices\BillingType;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable table filter that separates carrier charges by the invoice-authoritative billing type —
 * Prepaid (on-account) / Collect (consignee-billed) / 3rd Party — via CarrierCharge::scopeBillingType(),
 * which reads carrier_shipments.billing_type (UPS section / 3rd-party block, FedEx Payor). Drop into
 * any charge table's filters().
 */
class BillingTypeFilter
{
    public static function make(string $name = 'billing_type'): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Billing')
            ->options([
                BillingType::PREPAID => 'Prepaid',
                BillingType::COLLECT => 'Collect',
                BillingType::THIRD_PARTY => '3rd Party',
            ])
            ->query(fn (Builder $query, array $data): Builder => in_array($data['value'] ?? null, [BillingType::PREPAID, BillingType::COLLECT, BillingType::THIRD_PARTY], true)
                ? $query->billingType($data['value'])
                : $query);
    }
}
