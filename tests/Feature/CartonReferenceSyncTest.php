<?php

use App\Models\CartonCost;
use App\Services\Recoup\CartonCostSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('carton sync maps and stores the U_reference fields off the Pace carton', function () {
    $svc = new CartonCostSyncService;

    // mapCartonRow pulls the three custom refs out of the parsed Carton value object.
    $row = $svc->mapCartonRow([
        'tracking_number' => '1ZREF',
        'ship_cost' => 5.00,
        'ship_date' => '2026-07-01',
        'pace_job_number' => '252006',
        'pace_customer_id' => '3035',
        'U_reference' => 'P17472',
        'U_reference2' => '252006',
        'U_reference3' => null,
    ]);

    expect($row['U_reference'])->toBe('P17472')
        ->and($row['U_reference2'])->toBe('252006')
        ->and($row['U_reference3'])->toBeNull();

    // upsert persists them onto carton_costs.
    $svc->upsert([$row]);

    $cc = CartonCost::where('tracking_number', '1ZREF')->first();
    expect($cc->U_reference)->toBe('P17472')
        ->and($cc->U_reference2)->toBe('252006')
        ->and($cc->U_reference3)->toBeNull();
});

test('the field map requests the uppercase U_reference xpaths (lowercase is rejected by Pace)', function () {
    // Guards the case-sensitivity gotcha: FindObjects rejects @u_reference; it must be @U_reference.
    $map = (fn () => $this->paceFieldMap)->call(new CartonCostSyncService);

    expect($map['U_reference'])->toBe('shipment/@U_reference')
        ->and($map['U_reference2'])->toBe('shipment/@U_reference2')
        ->and($map['U_reference3'])->toBe('shipment/@U_reference3');
});
