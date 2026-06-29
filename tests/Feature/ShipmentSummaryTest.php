<?php

use App\Models\CarrierCharge;
use App\Models\CarrierShipmentSummary;
use Illuminate\Database\Eloquent\Relations\HasMany;

it('relates a shipment summary to its charge lines by tracking number', function () {
    $relation = (new CarrierShipmentSummary)->charges();

    expect($relation)->toBeInstanceOf(HasMany::class)
        ->and($relation->getRelated())->toBeInstanceOf(CarrierCharge::class)
        ->and($relation->getForeignKeyName())->toBe('tracking_number');
});
