<?php

namespace App\Filament\Filters;

use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable table filter that separates carrier charges by billing type using
 * CarrierCharge::scopeThirdParty()/scopeOnAccount() (Pace flag first, base-charge
 * heuristic fallback). Drop into any charge table's filters().
 */
class BillingTypeFilter
{
    public static function make(string $name = 'billing_type'): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Billing')
            ->options([
                'third_party' => '3rd Party',
                'on_account' => 'On Account',
            ])
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'third_party' => $query->thirdParty(),
                'on_account' => $query->onAccount(),
                default => $query,
            });
    }
}
