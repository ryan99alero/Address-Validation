<?php

use App\Jobs\ProcessExportBatch;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function seedAlignmentBatch(): ImportBatch
{
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $batch = ImportBatch::factory()->create([
        'include_transit_times' => true,
        'find_best_service' => true,
        'field_mappings' => [
            ['source' => 'company', 'target' => 'input_company', 'position' => 0],
            ['source' => 'city', 'target' => 'input_city', 'position' => 1],
            ['source' => 'state', 'target' => 'input_state', 'position' => 2],
            ['source' => 'zipcode', 'target' => 'input_postal', 'position' => 3],
        ],
    ]);
    foreach ([['ACME Corp', 'WEST PALM BEACH'], ['ACME, INC', 'MIAMI'], ['Globex', 'BOCA RATON']] as $i => $r) {
        Address::factory()->create([
            'import_batch_id' => $batch->id, 'source_row_number' => $i + 1,
            'input_company' => $r[0], 'input_city' => $r[1], 'input_state' => 'FL', 'input_postal' => '33401',
            'output_address_1' => '100 Clematis St', 'output_city' => $r[1], 'output_state' => 'FL', 'output_postal' => '33401',
            'validation_status' => 'valid', 'is_residential' => false, 'classification' => 'commercial',
            'validated_by_carrier_id' => $carrier->id,
            'ship_via_service' => 'FedEx Ground', 'ship_via_days' => 3, 'ship_via_date' => '2026-07-20',
            'ship_via_meets_deadline' => true, 'fastest_service' => 'FedEx Standard Overnight',
            'fastest_date' => '2026-07-16', 'ground_service' => 'FedEx Ground', 'ground_date' => '2026-07-20',
            'distance_miles' => 850.5, 'recommended_ship_date' => '2026-07-15',
            'recommended_ship_service' => 'FedEx Ground', 'bestway_optimized' => true,
            'previous_ship_via_code' => 'FX1D', 'ship_via_code' => 'FXG',
        ]);
    }

    return $batch;
}

/**
 * Reproduces the user's real flow: import-mapping export of a transit+BestWay batch
 * with "Include service / transit results" on. Uses the same mapping shape they used
 * (company/contact/phone/add1/add2/city/state/zip/country) and realistic data that
 * includes commas + quotes, then reads the file back with a proper CSV parser to
 * verify every value lands under the correct header (no column shift).
 */
it('keeps every column aligned when appending service results to an import-mapping export', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);

    $batch = ImportBatch::factory()->create([
        'include_transit_times' => true,
        'find_best_service' => true,
        'field_mappings' => [
            ['source' => 'company', 'target' => 'input_company', 'position' => 0],
            ['source' => 'contact', 'target' => 'input_name', 'position' => 1],
            ['source' => 'phone', 'target' => 'extra_1', 'position' => 2],
            ['source' => 'add1', 'target' => 'input_address_1', 'position' => 3],
            ['source' => 'add2', 'target' => 'input_address_2', 'position' => 4],
            ['source' => 'city', 'target' => 'input_city', 'position' => 5],
            ['source' => 'state', 'target' => 'input_state', 'position' => 6],
            ['source' => 'zipcode', 'target' => 'input_postal', 'position' => 7],
            ['source' => 'country', 'target' => 'input_country', 'position' => 8],
        ],
    ]);

    // Realistic rows: one clean, one with a comma in the company, one with a quote.
    $rows = [
        ['ACME Corp', 'John Doe', '561-555-0100', '100 Clematis St', 'Ste 400', 'WEST PALM BEACH', 'FL', '33401', 'US'],
        ['ACME, INC', 'Jane "JD" Roe', '561-555-0101', '200 Datura St, Bldg 2', 'Unit 5', 'MIAMI', 'FL', '33130', 'US'],
        ['Globex', "O'Hara & Sons", '561-555-0102', '300 Ocean Dr', '', 'BOCA RATON', 'FL', '33432', 'US'],
    ];

    foreach ($rows as $i => $r) {
        Address::factory()->create([
            'import_batch_id' => $batch->id,
            'source_row_number' => $i + 1,
            'input_company' => $r[0], 'input_name' => $r[1], 'extra_data' => ['extra_1' => $r[2]],
            'input_address_1' => $r[3], 'input_address_2' => $r[4], 'input_city' => $r[5],
            'input_state' => $r[6], 'input_postal' => $r[7], 'input_country' => $r[8],
            // validated output (same city so we can spot it if it lands elsewhere)
            'output_address_1' => $r[3], 'output_city' => $r[5], 'output_state' => $r[6], 'output_postal' => $r[7],
            'validation_status' => 'valid', 'is_residential' => false, 'classification' => 'commercial',
            'validated_by_carrier_id' => $carrier->id,
            // transit + bestway + reverse-schedule results populated
            'ship_via_service' => 'FedEx Ground', 'ship_via_days' => 3, 'ship_via_date' => '2026-07-20',
            'ship_via_meets_deadline' => true, 'fastest_service' => 'FedEx Standard Overnight',
            'fastest_date' => '2026-07-16', 'ground_service' => 'FedEx Ground', 'ground_date' => '2026-07-20',
            'distance_miles' => 850.5, 'recommended_ship_date' => '2026-07-15',
            'recommended_ship_service' => 'FedEx Ground', 'bestway_optimized' => true,
            'previous_ship_via_code' => 'FX1D', 'ship_via_code' => 'FXG',
        ]);
    }

    $job = new ProcessExportBatch(
        batch: $batch,
        useImportMapping: true,
        appendValidationFields: true,
    );
    $job->handle();

    $path = Storage::disk('local')->path($batch->fresh()->export_file_path);
    $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(file($path), fn ($l) => trim($l) !== ''));

    $header = $lines[0];
    $data = array_slice($lines, 1);

    // 1) Every data row has exactly as many columns as the header.
    foreach ($data as $i => $row) {
        expect(count($row))->toBe(count($header), "row {$i} column count must match header");
    }

    // 2) City values land under the 'city' header, and NOT under any ship-date header.
    $cityIdx = array_search('city', $header, true);
    $cities = ['WEST PALM BEACH', 'MIAMI', 'BOCA RATON'];
    foreach ($data as $i => $row) {
        expect($row[$cityIdx])->toBe($cities[$i], "city must be under the 'city' column in row {$i}");
    }

    foreach (['Ship Via Delivery Date', 'Recommended Ship Date'] as $dateHeader) {
        $idx = array_search($dateHeader, $header, true);
        expect($idx)->not->toBeFalse("append should include '{$dateHeader}'");
        foreach ($data as $i => $row) {
            expect($row[$idx])->not->toBeIn($cities, "no city may appear under '{$dateHeader}' (row {$i})");
        }
    }

});

/**
 * Same check for the CUSTOM-TEMPLATE export path (ePace/WorldShip style), which is
 * the other place "Include service / transit results" appends columns. Includes a
 * template that ALREADY emits a ship-date column (a 'ShipDate' header) to see how the
 * dedup + append behaves.
 */
it('keeps columns aligned when appending service results to a custom-template export', function () {
    $batch = seedAlignmentBatch();

    $template = ExportTemplate::factory()->create([
        'include_header' => true,
        'field_layout' => [
            ['field' => 'company', 'header' => 'Company', 'position' => 0],
            ['field' => 'corrected_city', 'header' => 'City', 'position' => 1],
            ['field' => 'ship_via_delivery_date', 'header' => 'ShipDate', 'position' => 2],
            ['field' => 'corrected_state', 'header' => 'State', 'position' => 3],
        ],
    ]);

    $job = new ProcessExportBatch(
        batch: $batch,
        templateId: $template->id,
        useImportMapping: false,
        appendValidationFields: true,
    );
    $job->handle();

    $path = Storage::disk('local')->path($batch->fresh()->export_file_path);
    $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(file($path), fn ($l) => trim($l) !== ''));
    $header = $lines[0];
    $data = array_slice($lines, 1);

    foreach ($data as $i => $row) {
        expect(count($row))->toBe(count($header), "template row {$i} column count must match header");
    }

    $cityIdx = array_search('City', $header, true);
    $shipDateIdx = array_search('ShipDate', $header, true);
    $cities = ['WEST PALM BEACH', 'MIAMI', 'BOCA RATON'];
    foreach ($data as $i => $row) {
        expect($row[$cityIdx])->toBe($cities[$i], "city under 'City' header, row {$i}")
            ->and($row[$shipDateIdx])->not->toBeIn($cities, "no city under 'ShipDate' header, row {$i}");
    }
});
