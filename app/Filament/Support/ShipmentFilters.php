<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared filter primitives for the shipment/charge tables (All Shipments and All Charges) so the
 * two views filter identically and never drift. text() builds a single free-text filter; trackingMatch()
 * constrains a base table to rows whose tracking number matches a related table (carton_costs for
 * job/customer/reference, carrier_invoice_lines for the shipped-to address, carrier_shipments for zip).
 */
class ShipmentFilters
{
    /**
     * A single-text-input filter that applies $apply(query, value) when filled, with a chip indicator.
     *
     * @param  callable(Builder, string): Builder  $apply
     */
    public static function text(string $name, string $label, callable $apply): Filter
    {
        return Filter::make($name)
            ->schema([TextInput::make('value')->label($label)])
            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                ? $apply($query, trim((string) $data['value']))
                : $query)
            ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? $label.': '.$data['value'] : null);
    }

    /**
     * Constrain $baseTable to rows whose tracking_number matches a LIKE (or exact) on $column of a
     * related $joinTable, via a correlated EXISTS on the indexed tracking_number.
     */
    public static function trackingMatch(Builder $query, string $baseTable, string $joinTable, string $column, string $value, bool $exact = false): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->from($joinTable)
            ->whereColumn("{$joinTable}.tracking_number", "{$baseTable}.tracking_number")
            ->when(
                $exact,
                fn ($q) => $q->where("{$joinTable}.{$column}", $value),
                fn ($q) => $q->where("{$joinTable}.{$column}", 'like', '%'.$value.'%'),
            ));
    }

    /**
     * Era-correct carton match: constrain to rows whose stamped carton_cost_id points at a carton
     * whose $column matches. Use this on carrier_charges (which carries carton_cost_id) instead of
     * trackingMatch, so a recycled 1Z's old charge — carton_cost_id null — can't match a newer job.
     *
     * @param  string  $fkColumn  fully-qualified FK, e.g. "carrier_charges.carton_cost_id"
     */
    public static function cartonMatch(Builder $query, string $fkColumn, string $column, string $value, bool $exact = false): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->from('carton_costs')
            ->whereColumn('carton_costs.id', $fkColumn)
            ->when(
                $exact,
                fn ($q) => $q->where("carton_costs.{$column}", $value),
                fn ($q) => $q->where("carton_costs.{$column}", 'like', '%'.$value.'%'),
            ));
    }
}
