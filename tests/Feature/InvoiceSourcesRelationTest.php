<?php

use App\Models\CarrierInvoiceLine;
use App\Models\CorrectedAddress;
use Illuminate\Database\Eloquent\Relations\HasMany;

it('relates a corrected address to its source invoice lines', function () {
    $relation = (new CorrectedAddress)->invoiceLines();

    expect($relation)->toBeInstanceOf(HasMany::class)
        ->and($relation->getRelated())->toBeInstanceOf(CarrierInvoiceLine::class)
        ->and($relation->getForeignKeyName())->toBe('corrected_address_id');
});
