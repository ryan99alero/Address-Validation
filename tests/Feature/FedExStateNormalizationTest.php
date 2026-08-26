<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Services\Carriers\FedExCarrier;
use App\Services\Carriers\UpsCarrier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fedexRequestState(string $state): string
{
    $carrier = Carrier::where('slug', 'fedex')->first() ?? Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $svc = (new FedExCarrier)->setCarrier($carrier);
    $a = (new Address)->forceFill(['input_address_1' => '320 N Woodlawn St', 'input_city' => 'Wellington', 'input_state' => $state, 'input_postal' => '67151', 'input_country' => 'US']);
    $m = new ReflectionMethod($svc, 'formatAddressForRequest');
    $m->setAccessible(true);

    return $m->invoke($svc, $a)['stateOrProvinceCode'];
}

it('normalizes a full state name to the 2-letter code (FedEx 400s on the full name)', function () {
    expect(fedexRequestState('Kansas'))->toBe('KS')
        ->and(fedexRequestState('kansas'))->toBe('KS')
        ->and(fedexRequestState('New York'))->toBe('NY');
});

it('leaves a 2-letter code (or an unrecognized value) as-is', function () {
    expect(fedexRequestState('KS'))->toBe('KS')
        ->and(fedexRequestState('ks'))->toBe('KS')
        ->and(fedexRequestState('Freedonia'))->toBe('Freedonia');
});

it('normalizes state for UPS too (shared in AbstractCarrier — covers batch + single)', function () {
    $ups = Carrier::where('slug', 'ups')->first() ?? Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $svc = (new UpsCarrier)->setCarrier($ups);
    $a = (new Address)->forceFill(['input_address_1' => '1 Main St', 'input_city' => 'Wellington', 'input_state' => 'KANSAS', 'input_postal' => '67152', 'input_country' => 'US']);
    $m = new ReflectionMethod($svc, 'formatAddressForRequest');
    $m->setAccessible(true);

    expect($m->invoke($svc, $a)['PoliticalDivision1'])->toBe('KS');
});
