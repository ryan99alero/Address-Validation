<?php

use App\Jobs\ProcessPaceAddressCorrection;
use App\Models\IntegrationConnection;
use App\Models\IntegrationFieldMapping;
use App\Models\IntegrationObject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contactObjectWithResidential(): IntegrationObject
{
    $conn = IntegrationConnection::forceCreate(['name' => 'Pace API', 'driver' => IntegrationConnection::DRIVER_PACE]);
    $obj = IntegrationObject::forceCreate(['connection_id' => $conn->id, 'object_name' => 'Contact', 'display_name' => 'Contact']);
    foreach (['residential', 'address1'] as $f) {
        IntegrationFieldMapping::forceCreate([
            'object_id' => $obj->id, 'local_table' => 'contacts', 'local_field' => $f, 'local_type' => 'string',
            'external_field' => $f, 'external_xpath' => '@'.$f, 'external_type' => 'string', 'sync_on_push' => true,
        ]);
    }

    return $obj;
}

it('never pushes a null (unknown) residential over Pace, but still pushes real changes', function () {
    $obj = contactObjectWithResidential();
    $job = new ProcessPaceAddressCorrection(1, []);
    $method = new ReflectionMethod($job, 'buildContactChanges');
    $method->setAccessible(true);

    // Validator couldn't classify residential (null) but did correct the address.
    [$changes] = $method->invoke($job, $obj,
        ['residential' => null, 'address1' => '10 MAIN ST'],   // corrected
        ['residential' => 'false', 'address1' => '5 OLD RD'],  // current (Pace)
    );

    expect($changes)->toHaveKey('address1')             // real change still pushed
        ->and($changes['address1'])->toBe('10 MAIN ST')
        ->and($changes)->not->toHaveKey('residential'); // null residential NOT pushed → no wipe
});

it('still pushes a definite residential=true', function () {
    $obj = contactObjectWithResidential();
    $job = new ProcessPaceAddressCorrection(1, []);
    $method = new ReflectionMethod($job, 'buildContactChanges');
    $method->setAccessible(true);

    [$changes] = $method->invoke($job, $obj, ['residential' => true, 'address1' => '10 MAIN ST'], ['residential' => 'false', 'address1' => '10 MAIN ST']);

    expect($changes)->toHaveKey('residential')
        ->and($changes['residential'])->toBeTrue();
});
