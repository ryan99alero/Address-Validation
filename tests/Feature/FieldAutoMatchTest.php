<?php

use App\Services\ImportService;

function targets(array $headers): array
{
    return collect(app(ImportService::class)->autoMatchHeaders($headers))
        ->pluck('target', 'source')
        ->all();
}

it('auto-maps the BestWay/Transit fields that previously fell through', function () {
    $map = targets(['Address 1', 'City', 'State', 'Zip', 'ShipViaCode', 'Required On-Site Date']);

    expect($map)->toMatchArray([
        'ShipViaCode' => 'ship_via_code',
        'Required On-Site Date' => 'required_on_site_date',
    ]);
});

it('matches ship-via header variants (despite the "ship" prefix strip)', function () {
    expect(targets(['Ship Via Code'])['Ship Via Code'])->toBe('ship_via_code')
        ->and(targets(['ShipVia'])['ShipVia'])->toBe('ship_via_code')
        ->and(targets(['Ship Method'])['Ship Method'])->toBe('ship_via_code')
        ->and(targets(['Service'])['Service'])->toBe('ship_via_code');
});

it('matches on-site and ship date variants', function () {
    expect(targets(['On-Site Date'])['On-Site Date'])->toBe('required_on_site_date')
        ->and(targets(['OnSiteDate'])['OnSiteDate'])->toBe('required_on_site_date')
        ->and(targets(['Ship Date'])['Ship Date'])->toBe('requested_ship_date');
});

it('still matches the core address fields', function () {
    $map = targets(['Address', 'City', 'State', 'Zip', 'Country']);

    expect($map)->toMatchArray([
        'City' => 'input_city',
        'State' => 'input_state',
        'Zip' => 'input_postal',
        'Country' => 'input_country',
    ]);
});
