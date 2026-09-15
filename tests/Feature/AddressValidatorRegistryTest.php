<?php

use App\Models\Carrier;
use App\Services\AddressValidationService;
use App\Services\Carriers\FedExCarrier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('availableValidatorSlugs = active carriers that have a registered driver', function () {
    Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);
    Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS', 'is_active' => true]);
    Carrier::factory()->create(['slug' => 'smarty', 'name' => 'Smarty', 'is_active' => false]);   // inactive → excluded
    Carrier::factory()->create(['slug' => 'acme', 'name' => 'Acme', 'is_active' => true]);        // no driver → excluded

    expect((new AddressValidationService)->availableValidatorSlugs())->toBe(['fedex', 'ups']);
});

test('createCarrierService resolves the registered driver from config', function () {
    Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);

    expect((new AddressValidationService)->getCarrierService('fedex'))->toBeInstanceOf(FedExCarrier::class);
});

test('an active carrier with no registered driver is rejected (no silent default)', function () {
    Carrier::factory()->create(['slug' => 'acme', 'name' => 'Acme', 'is_active' => true]);

    (new AddressValidationService)->getCarrierService('acme');
})->throws(Exception::class, "No address-validation driver registered for carrier 'acme'");
