<?php

use App\Jobs\ProcessExportBatch;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Exercises the redesigned export: results are written IN-PLACE into the file's own
 * columns (ShipDate, ShipViaCode, ResidentialDelivery, AddressCleansing*) and only a
 * few genuinely-new columns are appended. Uses the user's real mapping shape + data
 * with commas/quotes, parsed back with a real CSV reader to prove every value lands
 * under the correct header.
 */
it('populates existing columns in place and keeps every column aligned', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);

    $batch = ImportBatch::factory()->create([
        'include_transit_times' => true,
        'find_best_service' => true,
        'field_mappings' => [
            ['source' => 'ShipToName', 'target' => 'input_company', 'position' => 0],
            ['source' => 'ShipToCity', 'target' => 'input_city', 'position' => 1],
            ['source' => 'ShipToState', 'target' => 'input_state', 'position' => 2],
            ['source' => 'ShipToZipCode', 'target' => 'input_postal', 'position' => 3],
            ['source' => 'ShipViaCode', 'target' => 'ship_via_code', 'position' => 4],
            ['source' => 'ShipDate', 'target' => 'requested_ship_date', 'position' => 5],
            ['source' => 'ResidentialDelivery', 'target' => 'input_is_residential', 'position' => 6],
            ['source' => 'AddressCleansingComment', 'target' => '_skip', 'position' => 7],
            ['source' => 'AddressCleansingReconciled', 'target' => '_skip', 'position' => 8],
        ],
    ]);

    Address::factory()->create([
        'import_batch_id' => $batch->id,
        'source_row_number' => 1,
        'input_company' => 'ACME, INC', // comma
        'input_address_1' => '100 Main St', 'input_address_2' => null,
        'input_city' => 'Chicopee', 'input_state' => 'MA', 'input_postal' => '01020',
        // validated output: city (case) + zip corrected, street unchanged
        'output_address_1' => '100 Main St', 'output_address_2' => null,
        'output_city' => 'CHICOPEE', 'output_state' => 'MA', 'output_postal' => '01020-5005',
        'validation_status' => 'valid', 'is_residential' => false,
        'validated_by_carrier_id' => $carrier->id,
        'requested_ship_date' => '2026-07-10',
        'required_on_site_date' => '2026-07-15',
        // BestWay JIT result
        'ship_via_code' => 'OV', 'previous_ship_via_code' => 'G1',
        'ship_via_service' => 'FedEx Standard Overnight', 'ship_via_days' => 1,
        'ship_via_date' => '2026-07-15', 'ship_via_meets_deadline' => true,
        'recommended_ship_date' => '2026-07-14', 'recommended_ship_service' => 'FedEx Standard Overnight',
        'bestway_optimized' => true,
    ]);

    $job = new ProcessExportBatch(batch: $batch, useImportMapping: true, appendValidationFields: true);
    $job->handle();

    $path = Storage::disk('local')->path($batch->fresh()->export_file_path);
    $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(file($path), fn ($l) => trim($l) !== ''));
    $header = $rows[0];
    $row = $rows[1];
    $col = fn (string $h) => $row[array_search($h, $header, true)];

    // Alignment held despite the comma.
    expect(count($row))->toBe(count($header));

    // Existing columns populated in place with computed values.
    expect($col('ShipViaCode'))->toBe('OV')                                  // new BestWay code
        ->and($col('ShipDate'))->toBe('07/14/2026')                          // JIT ship date, MM/DD/YYYY
        ->and($col('ResidentialDelivery'))->toBe('N')                        // validated residential
        ->and($col('AddressCleansingComment'))->toBe('City: CHICOPEE, Zip: 01020-5005')
        ->and($col('AddressCleansingReconciled'))->toBe('valid')
        ->and($col('ShipToCity'))->toBe('CHICOPEE');                         // corrected address written back

    // Only the BestWay service-result columns are appended — not the old ~15.
    $appended = array_slice($header, 9);
    expect($appended)->toBe(['Ship Via Service', 'Ship Via Transit Days', 'Ship Via Meets Deadline', 'BestWay Optimized', 'ShipMethodComment'])
        ->and($header)->not->toContain('Fastest Service')
        ->and($header)->not->toContain('Distance (Miles)')
        ->and($header)->not->toContain('What Changed');

    expect($col('Ship Via Service'))->toBe('FedEx Standard Overnight')
        ->and($col('Ship Via Transit Days'))->toBe('1')
        ->and($col('Ship Via Meets Deadline'))->toBe('Yes')
        ->and($col('BestWay Optimized'))->toBe('Yes')
        ->and($col('ShipMethodComment'))->toContain('FedEx Standard Overnight')
        ->and($col('ShipMethodComment'))->toContain('ship 07/14/2026')
        ->and($col('ShipMethodComment'))->toContain('arrive 07/15/2026');
});

/**
 * Batch-170 scenario: an address-validation-only export with the service-results toggle OFF still
 * fills the address-cleansing columns (they're validation outputs, not service results), and appends
 * NO service/transit columns.
 */
it('fills address-cleansing columns even with the service-results toggle off', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);

    $batch = ImportBatch::factory()->create([
        'field_mappings' => [
            ['source' => 'ShipToCity', 'target' => 'input_city', 'position' => 0],
            ['source' => 'ShipToState', 'target' => 'input_state', 'position' => 1],
            ['source' => 'ShipToZipCode', 'target' => 'input_postal', 'position' => 2],
            ['source' => 'ResidentialDelivery', 'target' => 'input_is_residential', 'position' => 3],
            ['source' => 'AddressCleansingComment', 'target' => '_skip', 'position' => 4],
            ['source' => 'AddressCleansingReconciled', 'target' => '_skip', 'position' => 5],
        ],
    ]);

    Address::factory()->create([
        'import_batch_id' => $batch->id,
        'source_row_number' => 1,
        'input_city' => 'Chicopee', 'input_state' => 'MA', 'input_postal' => '01020',
        'output_city' => 'CHICOPEE', 'output_state' => 'MA', 'output_postal' => '01020-5005',
        'validation_status' => 'valid', 'is_residential' => false,
        'validated_by_carrier_id' => $carrier->id,
    ]);

    // appendValidationFields defaults to false — the correction-only export path.
    $job = new ProcessExportBatch(batch: $batch, useImportMapping: true);
    $job->handle();

    $path = Storage::disk('local')->path($batch->fresh()->export_file_path);
    $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(file($path), fn ($l) => trim($l) !== ''));
    $header = $rows[0];
    $row = $rows[1];
    $col = fn (string $h) => $row[array_search($h, $header, true)];

    // Cleansing/residential columns filled from validation data despite the toggle being off.
    expect($col('ResidentialDelivery'))->toBe('N')
        ->and($col('AddressCleansingComment'))->toBe('City: CHICOPEE, Zip: 01020-5005')
        ->and($col('AddressCleansingReconciled'))->toBe('valid');

    // No service/transit columns appended.
    expect($header)->toBe(['ShipToCity', 'ShipToState', 'ShipToZipCode', 'ResidentialDelivery', 'AddressCleansingComment', 'AddressCleansingReconciled'])
        ->and($header)->not->toContain('Ship Via Transit Days')
        ->and($header)->not->toContain('BestWay Optimized');
});
