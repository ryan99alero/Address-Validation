<?php

use App\Services\Invoices\FedExInvoiceParser;

/**
 * The FedEx PDF "Service Type" column (the line right after the tracking number) is printed two
 * different ways: EXPRESS invoices name the real service there ("FedEx 2Day"); GROUND invoices put
 * the payment term there ("Ppd, Domestic") and carry the product as a section header. These blocks
 * are the real text (via smalot) of two 2026 invoices, trimmed to the shipment detail rows. Before
 * the fix, digit-initial Express services fell through to NULL / the payment term, and every Ground
 * shipment recorded "Ppd, Domestic" instead of "FedEx Ground".
 */
function callExtractServiceType(string $block, ?string $product = null): ?string
{
    $parser = new FedExInvoiceParser;
    $method = new ReflectionMethod($parser, 'extractServiceType');
    $method->setAccessible(true);

    return $method->invoke($parser, $block, 'Ground', $product);
}

function callDetectProduct(string $text): ?string
{
    $parser = new FedExInvoiceParser;
    $method = new ReflectionMethod($parser, 'detectProduct');
    $method->setAccessible(true);

    return $method->invoke($parser, $text);
}

test('Express: reads a digit-initial service (FedEx 2Day) from the Service Type column', function () {
    $block = "Automation\nTracking ID\nService Type\nPackage Type\nZone\nFAPI\n875774818921\nFedEx 2Day\nCustomer Packaging\n12\n";

    expect(callExtractServiceType($block))->toBe('FedEx 2Day');
});

test('Express: still reads a letter-initial service (FedEx Priority Overnight)', function () {
    $block = "FAPI\n123456789012\nFedEx Priority Overnight\nFedEx Envelope\n2\n";

    expect(callExtractServiceType($block))->toBe('FedEx Priority Overnight');
});

test('Ground: a payment-term Service Type on a Ground invoice resolves to FedEx Ground', function () {
    $block = "Tracking ID\nService Type\nZone\nPackages\n876439104212\nPpd, Domestic\n4\n1\n";

    expect(callExtractServiceType($block, 'ground'))->toBe('FedEx Ground');
});

test('Ground: Home Delivery self-identifies even inside the payment-term column', function () {
    $block = "Tracking ID\nService Type\n875777189681\nHome Delivery Ppd\n5\n1\n";

    expect(callExtractServiceType($block, 'ground'))->toBe('FedEx Home Delivery');
});

test('a payment-term Service Type is NOT called Ground when the invoice is not a Ground invoice', function () {
    $block = "Tracking ID\nService Type\n876439104212\nPpd, Domestic\n4\n";

    // No product context (e.g. Express invoice) → do not fabricate a Ground service.
    expect(callExtractServiceType($block, null))->toBe('Ppd, Domestic');
});

test('detectProduct recognises a FedEx Ground invoice from its section markers', function () {
    expect(callDetectProduct("...\nTotal FedEx Ground\tUSD\t\$40,123.88\n..."))->toBe('ground')
        ->and(callDetectProduct("FedEx 2Day\nTransportation Charge\t151.55\n"))->toBeNull();
});
