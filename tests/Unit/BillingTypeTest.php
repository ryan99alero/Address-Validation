<?php

use App\Services\Invoices\BillingType;

test('forUps: 3rd-party wins, inbound is collect, everything else prepaid', function () {
    expect(BillingType::forUps('outbound', true))->toBe('third_party')
        ->and(BillingType::forUps('inbound', true))->toBe('third_party')
        ->and(BillingType::forUps('inbound', false))->toBe('collect')
        ->and(BillingType::forUps('outbound', false))->toBe('prepaid')
        ->and(BillingType::forUps(null, false))->toBe('prepaid');
});

test('fromServiceTerm maps the FedEx/UPS payment-term prefix', function () {
    expect(BillingType::fromServiceTerm('Ppd, Domestic'))->toBe('prepaid')
        ->and(BillingType::fromServiceTerm('Collect, Domestic'))->toBe('collect')
        ->and(BillingType::fromServiceTerm('Bill 3rd Party, Dom'))->toBe('third_party')
        ->and(BillingType::fromServiceTerm('Bill Recipient'))->toBe('third_party')
        ->and(BillingType::fromServiceTerm('FedEx 2Day'))->toBeNull()
        ->and(BillingType::fromServiceTerm(''))->toBeNull();
});

test('forFedEx falls back to the is_third_party flag when the term is a real service', function () {
    expect(BillingType::forFedEx('Collect, Domestic', false))->toBe('collect')
        ->and(BillingType::forFedEx('FedEx 2Day', true))->toBe('third_party')
        ->and(BillingType::forFedEx('FedEx 2Day', false))->toBe('prepaid')
        ->and(BillingType::forFedEx(null, false))->toBe('prepaid');
});
