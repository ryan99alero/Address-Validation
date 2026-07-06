<?php

use App\Models\CarrierInvoiceLine;

test('linkToCorrectionCache skips (not crashes) when the corrected city/state/postal is null', function () {
    // A correction parsed with an address_1 but no city — previously this crashed the whole
    // file's import via a TypeError in findOrCreateFromCorrection.
    $line = (new CarrierInvoiceLine)->forceFill([
        'corrected_address_1' => '123 Main St',
        'corrected_city' => null,
        'corrected_state' => 'KS',
        'corrected_postal' => '67209',
    ]);

    expect($line->linkToCorrectionCache())->toBeFalse();
});

test('linkToCorrectionCache returns false when there is no correction at all', function () {
    $line = (new CarrierInvoiceLine)->forceFill(['corrected_address_1' => null]);

    expect($line->linkToCorrectionCache())->toBeFalse();
});
