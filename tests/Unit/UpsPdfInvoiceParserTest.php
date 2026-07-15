<?php

use App\Services\Invoices\UpsPdfInvoiceParser;

/** Representative extracted text from a real UPS invoice Address Corrections section. */
function upsCorrectionText(): string
{
    return 'Delivery Service Invoice Invoice Date May 23, 2026 Invoice Number 0000691317216 Account Number 691317 '
        .'Address Corrections Tracking Number Service Number of Packages Published Charge Incentive Credit Billed Charge '
        .'1Z6913170390656918 Ground 1 25.25 -5.05 20.20 Fuel Surcharge 7.01 -3.36 3.65 1st ref: RGV 2nd ref: 25 '
        .'Recorded: STORE MANAGER PIZZA HUT 39499 9001 FM 1472 LAREDO TX 78041 '
        .'Corrected: PIZZA HUT 39499 9001 MINES RD LAREDO TX 78045 '
        .'1Z6913170390774175 Ground 1 25.25 -5.05 20.20 '
        .'Recorded: BRANDON RAICHE 1421 34TH ST N ST PETERSBURG FL 33764 '
        .'Corrected: BRANDON RAICHE 1421 34TH ST N SAINT PETERSBURG FL 33713 Total Collect';
}

test('parses invoice metadata', function () {
    $parsed = (new UpsPdfInvoiceParser)->parse(upsCorrectionText());

    expect($parsed['invoice_number'])->toBe('0000691317216');
    expect($parsed['account_number'])->toBe('691317');
});

test('extracts every Recorded/Corrected pair with its tracking number', function () {
    $corrections = (new UpsPdfInvoiceParser)->extractCorrections(upsCorrectionText());

    expect($corrections)->toHaveCount(2);
    expect($corrections[0]['tracking_number'])->toBe('1Z6913170390656918');
    expect($corrections[1]['tracking_number'])->toBe('1Z6913170390774175');
});

test('captures the address-correction billed fee (not $0) from the correction line', function () {
    $corrections = (new UpsPdfInvoiceParser)->extractCorrections(upsCorrectionText());

    // Billed Charge column of the correction line = the $20.20 flat fee (ignoring the
    // fuel surcharge and the "1st ref:" reference number that also look like amounts).
    expect($corrections[0]['charge_amount'])->toBe(20.20);
    expect($corrections[1]['charge_amount'])->toBe(20.20);
});

test('parses the corrected address fields', function () {
    $corrections = (new UpsPdfInvoiceParser)->extractCorrections(upsCorrectionText());
    $corrected = $corrections[0]['corrected'];

    expect($corrected['address_1'])->toBe('9001 MINES RD');
    expect($corrected['city'])->toBe('LAREDO');
    expect($corrected['state'])->toBe('TX');
    expect($corrected['postal'])->toBe('78045');
});

test('handles Texas FM roads (no standard suffix)', function () {
    $addr = (new UpsPdfInvoiceParser)->parseAddress('STORE MANAGER PIZZA HUT 39499 9001 FM 1472 LAREDO TX 78041');

    expect($addr['address_1'])->toBe('9001 FM 1472');
    expect($addr['city'])->toBe('LAREDO');
    expect($addr['state'])->toBe('TX');
    expect($addr['postal'])->toBe('78041');
});

test('does not mistake the state FL for a Floor unit', function () {
    $corrections = (new UpsPdfInvoiceParser)->extractCorrections(upsCorrectionText());
    $recorded = $corrections[1]['recorded'];

    expect($recorded['state'])->toBe('FL');
    expect($recorded['postal'])->toBe('33764');
    expect($recorded['address_2'])->toBeNull();
});

test('extracts a Suite into address_2 and keeps the city clean', function () {
    $addr = (new UpsPdfInvoiceParser)->parseAddress('RICO PIZZA HUT 4416 N CONWAY AVE Suite: 114 MISSION TX 78573');

    expect($addr['address_2'])->toBe('SUITE: 114');
    expect($addr['city'])->toBe('MISSION');
    expect($addr['state'])->toBe('TX');
    expect($addr['postal'])->toBe('78573');
});
