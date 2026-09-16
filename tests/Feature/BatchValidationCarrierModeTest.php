<?php

use App\Jobs\ProcessImportBatchValidation;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\ImportBatch;
use App\Models\ShipViaCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Invoke the protected groupByCarrier and return slug => [ship_via_codes] for easy assertions. */
function groupModes(ProcessImportBatchValidation $job, array $addresses, ?string $override): array
{
    $m = new ReflectionMethod($job, 'groupByCarrier');
    $m->setAccessible(true);

    return collect($m->invoke($job, $addresses, $override))
        ->map(fn ($rows): array => collect($rows)->pluck('ship_via_code')->all())
        ->all();
}

function modeAddress(?string $shipViaCode): Address
{
    return Address::create([
        'input_address_1' => '1 Main St', 'input_city' => 'Wichita', 'input_state' => 'KS',
        'input_postal' => '67209', 'input_country' => 'US', 'validation_status' => 'pending',
        'source' => 'api', 'ship_via_code' => $shipViaCode,
    ]);
}

beforeEach(function () {
    $fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);
    $ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS', 'is_active' => true]);
    ShipViaCode::create(['code' => 'FDG', 'carrier_id' => $fedex->id, 'service_type' => 'FEDEX_GROUND', 'service_name' => 'FedEx Ground', 'is_active' => true]);
    ShipViaCode::create(['code' => 'UPG', 'carrier_id' => $ups->id, 'service_type' => 'GND', 'service_name' => 'UPS Ground', 'is_active' => true]);

    $this->job = new ProcessImportBatchValidation(ImportBatch::create([
        'original_filename' => 'x.csv', 'file_path' => 'x', 'status' => 'mapping', 'total_rows' => 3,
        'carrier_id' => $fedex->id, 'validation_engine' => 'auto',
    ]));
    $this->rows = [modeAddress('FDG'), modeAddress('UPG'), modeAddress(null)];
});

it('auto mode groups each row by its own ship-via carrier (mixed file), none -> fallback bucket', function () {
    $groups = groupModes($this->job, $this->rows, null);

    expect($groups['fedex'])->toBe(['FDG'])
        ->and($groups['ups'])->toBe(['UPG'])
        ->and($groups[''])->toBe([null]); // '' key => null primary => Fall Back Priority list
});

it('override mode forces every row to the chosen carrier, ignoring ship-via', function () {
    $groups = groupModes($this->job, $this->rows, 'fedex');

    expect(array_keys($groups))->toBe(['fedex'])
        ->and($groups['fedex'])->toBe(['FDG', 'UPG', null]);
});
