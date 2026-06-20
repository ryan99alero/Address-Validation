<?php

use App\Services\Invoices\FedExInvoiceParser;

/**
 * Synthetic text mimicking smalot's per-line output for the two FedEx layouts
 * plus the Ground Address Correction section.
 */
function fedexSampleText(): string
{
    return implode("\n", [
        'Invoice Number 9-125-46831',
        'Account Number 2261-2560-4',
        'Invoice Date Jan 01, 2026',
        // Express block: "<noise>\t<Label>\t<Amount>", closes with Total Transportation Charges
        'Ship Date: Dec 12, 2025',
        'Recipient',
        'JOHN DOE',
        'DALLAS TX  75001  US',
        'Packages 1',
        "Actual Weight 7.0 lbs\tTransportation Charge \t194.75",
        "FedEx Use _/X/_\tFuel Surcharge \t23.65",
        "Total Transportation Charges\tUSD\t\$218.40",
        // Ground block: labels then amounts, closes with Total Charge USD
        'Ship Date: Dec 13, 2025',
        'Packages',
        '395784492149',
        'Recipient',
        'JANE ROE',
        'AUSTIN TX  78701  US',
        'Fuel Surcharge',
        'Address Correction',
        '5.34',
        '24.00',
        'Total Charge USD $29.34',
        // Address-correction section
        'FedEx Ground Address Correction',
        'Tracking ID: 395784492149',
        'OLD CO 1 OLD ST DALLAS TX 75001 US',
        'NEW CO 2 NEW ST AUSTIN TX 78701 US',
    ]);
}

test('parses express and ground charge ledgers that reconcile to the total', function () {
    $result = (new FedExInvoiceParser)->parseText(fedexSampleText());

    expect($result['reconciled'])->toBe(2)
        ->and($result['skipped'])->toBe(0);

    $express = $result['shipments'][0];
    expect($express['type'])->toBe('Express')
        ->and($express['total_charge'])->toBe(218.40)
        ->and($express['charge_ledger'])->toHaveCount(2)
        ->and(collect($express['charge_ledger'])->firstWhere('description', 'Transportation Charge')['amount'])->toBe(194.75)
        ->and(collect($express['charge_ledger'])->firstWhere('description', 'Fuel Surcharge')['amount'])->toBe(23.65);

    $ground = $result['shipments'][1];
    expect($ground['type'])->toBe('Ground')
        ->and($ground['total_charge'])->toBe(29.34)
        ->and(collect($ground['charge_ledger'])->firstWhere('description', 'Address Correction')['amount'])->toBe(24.00);
});

test('extracts original and corrected addresses from the correction section', function () {
    $result = (new FedExInvoiceParser)->parseText(fedexSampleText());

    expect($result['corrections'])->toHaveCount(1);
    expect($result['corrections'][0]['tracking'])->toBe('395784492149');
    expect($result['corrections'][0]['original'])->toContain('OLD ST DALLAS TX 75001 US');
    expect($result['corrections'][0]['corrected'])->toContain('NEW ST AUSTIN TX 78701 US');
});

test('skips a block whose charges do not reconcile to its total', function () {
    $bad = "Ship Date: Dec 12, 2025\nPackages 1\n"
        ."Junk\tFuel Surcharge \t10.00\n"
        ."Total Transportation Charges\tUSD\t\$999.99";

    $result = (new FedExInvoiceParser)->parseText($bad);

    expect($result['reconciled'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});
