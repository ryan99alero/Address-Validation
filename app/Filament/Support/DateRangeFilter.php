<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * A reusable From/Until date-range filter keyed on a record's OWN "happened" date — the ship date,
 * invoice date, shipment date, correction time, etc. — NOT created_at / import date. This matters
 * because re-imported historical invoices carry a recent import date but old real dates; filtering
 * should be about when the thing actually happened. Drop `DateRangeFilter::make('ship_date', 'Ship date')`
 * into any table's ->filters([]).
 */
class DateRangeFilter
{
    public static function make(string $column, string $label = 'Date'): Filter
    {
        $name = 'range_'.str_replace('.', '_', $column);

        return Filter::make($name)
            ->schema([
                DatePicker::make('from')->label($label.' from')->native(false),
                DatePicker::make('until')->label($label.' until')->native(false),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate($column, '<=', $date)))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];
                if ($data['from'] ?? null) {
                    $indicators[] = $label.' ≥ '.$data['from'];
                }
                if ($data['until'] ?? null) {
                    $indicators[] = $label.' ≤ '.$data['until'];
                }

                return $indicators;
            });
    }
}
