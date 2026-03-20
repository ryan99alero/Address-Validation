<?php

use App\Jobs\ProcessExportBatch;
use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use App\Services\ExportService;

describe('ExportService field value extraction', function () {
    beforeEach(function () {
        $this->service = new ExportService;

        // Create carrier
        $this->carrier = Carrier::factory()->create([
            'name' => 'UPS',
            'slug' => 'ups',
        ]);

        // Create address with correction
        $this->address = Address::factory()->create([
            'external_reference' => 'ORDER-12345',
            'name' => 'John Doe',
            'company' => 'Acme Corp',
            'address_line_1' => '123 Main St',
            'address_line_2' => 'Suite 100',
            'city' => 'Springfield',
            'state' => 'IL',
            'postal_code' => '62701',
            'country_code' => 'US',
            'extra_1' => 'Custom Data 1',
            'extra_5' => 'Custom Data 5',
        ]);

        $this->correction = AddressCorrection::factory()->create([
            'address_id' => $this->address->id,
            'carrier_id' => $this->carrier->id,
            'validation_status' => 'valid',
            'corrected_address_line_1' => '123 MAIN ST',
            'corrected_address_line_2' => 'STE 100',
            'corrected_city' => 'SPRINGFIELD',
            'corrected_state' => 'IL',
            'corrected_postal_code' => '62701',
            'corrected_postal_code_ext' => '1234',
            'is_residential' => false,
            'classification' => 'commercial',
            'confidence_score' => 0.95,
        ]);

        // Refresh to load relationship
        $this->address->refresh();
    });

    it('extracts original address fields', function () {
        expect($this->service->getFieldValue($this->address, 'external_reference'))->toBe('ORDER-12345');
        expect($this->service->getFieldValue($this->address, 'name'))->toBe('John Doe');
        expect($this->service->getFieldValue($this->address, 'company'))->toBe('Acme Corp');
        expect($this->service->getFieldValue($this->address, 'original_address_line_1'))->toBe('123 Main St');
        expect($this->service->getFieldValue($this->address, 'original_city'))->toBe('Springfield');
    });

    it('extracts corrected address fields', function () {
        expect($this->service->getFieldValue($this->address, 'corrected_address_line_1'))->toBe('123 MAIN ST');
        expect($this->service->getFieldValue($this->address, 'corrected_address_line_2'))->toBe('STE 100');
        expect($this->service->getFieldValue($this->address, 'corrected_city'))->toBe('SPRINGFIELD');
        expect($this->service->getFieldValue($this->address, 'corrected_state'))->toBe('IL');
        expect($this->service->getFieldValue($this->address, 'corrected_postal_code'))->toBe('62701');
        expect($this->service->getFieldValue($this->address, 'corrected_postal_code_ext'))->toBe('1234');
    });

    it('formats full postal code with extension', function () {
        expect($this->service->getFieldValue($this->address, 'full_postal_code'))->toBe('62701-1234');
    });

    it('extracts validation fields', function () {
        expect($this->service->getFieldValue($this->address, 'validation_status'))->toBe('valid');
        expect($this->service->getFieldValue($this->address, 'is_residential'))->toBe('No');
        expect($this->service->getFieldValue($this->address, 'classification'))->toBe('commercial');
        expect($this->service->getFieldValue($this->address, 'confidence_score'))->toBe('95%');
        expect($this->service->getFieldValue($this->address, 'carrier'))->toBe('UPS');
    });

    it('extracts extra fields for pass-through', function () {
        expect($this->service->getFieldValue($this->address, 'extra_1'))->toBe('Custom Data 1');
        expect($this->service->getFieldValue($this->address, 'extra_5'))->toBe('Custom Data 5');
        expect($this->service->getFieldValue($this->address, 'extra_10'))->toBeNull();
    });

    it('returns null for unknown fields', function () {
        expect($this->service->getFieldValue($this->address, 'unknown_field'))->toBeNull();
    });
});

describe('ExportService export data generation', function () {
    beforeEach(function () {
        $this->service = new ExportService;
    });

    it('generates export data with headers when include_header is true', function () {
        $carrier = Carrier::factory()->create();
        $address = Address::factory()->create();
        AddressCorrection::factory()->create([
            'address_id' => $address->id,
            'carrier_id' => $carrier->id,
        ]);
        $address->refresh();

        $template = ExportTemplate::factory()->create([
            'include_header' => true,
            'field_layout' => [
                ['field' => 'external_reference', 'header' => 'RefNum', 'position' => 1],
                ['field' => 'corrected_city', 'header' => 'City', 'position' => 2],
            ],
        ]);

        $data = $this->service->getExportData(collect([$address]), $template);

        // First row should be headers
        expect($data[0])->toBe(['RefNum', 'City']);
        // Second row should be data
        expect($data[1][0])->toBe($address->external_reference);
    });

    it('generates export data without headers when include_header is false', function () {
        $carrier = Carrier::factory()->create();
        $address = Address::factory()->create(['external_reference' => 'TEST-001']);
        AddressCorrection::factory()->create([
            'address_id' => $address->id,
            'carrier_id' => $carrier->id,
            'corrected_city' => 'TESTCITY',
        ]);
        $address->refresh();

        $template = ExportTemplate::factory()->create([
            'include_header' => false,
            'field_layout' => [
                ['field' => 'external_reference', 'header' => 'RefNum', 'position' => 1],
                ['field' => 'corrected_city', 'header' => 'City', 'position' => 2],
            ],
        ]);

        $data = $this->service->getExportData(collect([$address]), $template);

        // First row should be data, not headers
        expect($data[0][0])->toBe('TEST-001');
        expect($data[0][1])->toBe('TESTCITY');
    });
});

describe('ExportService available fields', function () {
    it('includes all standard fields', function () {
        $fields = ExportService::getAvailableFields();

        expect($fields)->toHaveKey('external_reference');
        expect($fields)->toHaveKey('name');
        expect($fields)->toHaveKey('company');
        expect($fields)->toHaveKey('corrected_address_line_1');
        expect($fields)->toHaveKey('validation_status');
        expect($fields)->toHaveKey('confidence_score');
    });

    it('includes all extra fields', function () {
        $fields = ExportService::getAvailableFields();

        for ($i = 1; $i <= 20; $i++) {
            expect($fields)->toHaveKey("extra_{$i}");
        }
    });
});

describe('ProcessExportBatch with validation fields', function () {
    it('returns validation fields to append for basic batch', function () {
        $batch = ImportBatch::factory()->create([
            'include_transit_times' => false,
            'field_mappings' => [
                ['source' => 'Name', 'target' => 'name', 'position' => 0],
            ],
        ]);

        $job = new ProcessExportBatch(
            batch: $batch,
            appendValidationFields: true
        );

        // Use reflection to call protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getValidationFieldsToAppend');
        $method->setAccessible(true);

        $fields = $method->invoke($job);

        // Should have core validation fields
        $fieldNames = array_column($fields, 'field');
        expect($fieldNames)->toContain('corrected_address_line_1');
        expect($fieldNames)->toContain('validation_status');
        expect($fieldNames)->toContain('is_residential');
        expect($fieldNames)->toContain('carrier');

        // Should NOT have transit time fields
        expect($fieldNames)->not->toContain('ship_via_service');
        expect($fieldNames)->not->toContain('fastest_service');
    });

    it('includes transit time fields when batch has them enabled', function () {
        $batch = ImportBatch::factory()->create([
            'include_transit_times' => true,
            'field_mappings' => [
                ['source' => 'Name', 'target' => 'name', 'position' => 0],
            ],
        ]);

        $job = new ProcessExportBatch(
            batch: $batch,
            appendValidationFields: true
        );

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getValidationFieldsToAppend');
        $method->setAccessible(true);

        $fields = $method->invoke($job);
        $fieldNames = array_column($fields, 'field');

        // Should have core validation fields
        expect($fieldNames)->toContain('corrected_address_line_1');

        // Should also have transit time fields
        expect($fieldNames)->toContain('ship_via_service');
        expect($fieldNames)->toContain('ship_via_transit_days');
        expect($fieldNames)->toContain('recommended_service');
        expect($fieldNames)->toContain('fastest_service');
        expect($fieldNames)->toContain('distance_miles');
    });
});
