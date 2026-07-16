<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Services\FedExServiceAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buildFedexPayload(Address $address): array
{
    $carrier = Carrier::firstOrCreate(['slug' => 'fedex'], Carrier::factory()->raw(['slug' => 'fedex']));
    $service = new FedExServiceAvailabilityService($carrier);
    $method = (new ReflectionClass($service))->getMethod('buildPayloadForAddress');
    $method->setAccessible(true);

    return $method->invoke($service, $address, ['postalCode' => '67215', 'countryCode' => 'US']);
}

it('sends the ship date under the case-sensitive FedEx key and honors the requested ship date', function () {
    // A genuinely future ship date (relative to now, so the test never floors it to today).
    $future = now()->addDays(30)->toDateString();
    $address = Address::factory()->create([
        'input_postal' => '04652', 'requested_ship_date' => $future, 'is_residential' => true,
    ]);

    $shipment = buildFedexPayload($address)['requestedShipment'];

    // Correct key is "shipDatestamp" (lower-case "stamp"); the camelCase "shipDateStamp"
    // was silently ignored by FedEx, so transit computed from today instead of this date.
    expect($shipment)->toHaveKey('shipDatestamp')
        ->and($shipment)->not->toHaveKey('shipDateStamp')
        ->and($shipment['shipDatestamp'])->toBe($future);
});

it('includes pickupType, both carrier codes, and the recipient residential flag', function () {
    $residential = Address::factory()->create(['input_postal' => '04652', 'is_residential' => true]);
    $commercial = Address::factory()->create(['input_postal' => '10001', 'is_residential' => false]);

    $r = buildFedexPayload($residential);
    $c = buildFedexPayload($commercial);

    expect($r['requestedShipment']['pickupType'])->toBe('USE_SCHEDULED_PICKUP')
        ->and($r['carrierCodes'])->toBe(['FDXE', 'FDXG'])
        ->and($r['requestedShipment']['recipients'][0]['address']['residential'])->toBeTrue()
        ->and($c['requestedShipment']['recipients'][0]['address']['residential'])->toBeFalse();
});
