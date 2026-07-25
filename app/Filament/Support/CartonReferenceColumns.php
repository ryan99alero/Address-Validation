<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;

/**
 * Reference / Reference 2 / Reference 3, resolved from the Pace carton mirror (carton_costs) by
 * tracking number via the `cartonCost` relationship. Reference 2 mirrors the job number. Reference 3
 * is kept but hidden by default — no data populates it yet, so it's a toggleable column ready for
 * when Pace starts writing it. Searchable so a shipment can be found by its reference or job.
 *
 * Shared by the Adjustments (carrier_charges) grid and both chargeback views — any model with a
 * `cartonCost` belongsTo (by tracking_number) can drop these in.
 */
class CartonReferenceColumns
{
    /**
     * @return array<int, TextColumn>
     */
    public static function make(): array
    {
        return [
            TextColumn::make('cartonCost.U_reference')->label('Reference')->searchable()->placeholder('—')->toggleable(),
            TextColumn::make('cartonCost.U_reference2')->label('Reference 2')->searchable()->placeholder('—')->toggleable(),
            TextColumn::make('cartonCost.U_reference3')->label('Reference 3')->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}
