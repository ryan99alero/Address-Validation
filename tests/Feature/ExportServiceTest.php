<?php

use App\Jobs\ProcessExportBatch;
use App\Models\Address;
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

        // Create address with validation data (denormalized schema)
        $this->address = Address::factory()->create([
            'external_reference' => 'ORDER-12345',
            'input_name' => 'John Doe',
            'input_company' => 'Acme Corp',
            'input_address_1' => '123 Main St',
            'input_address_2' => 'Suite 100',
            'input_city' => 'Springfield',
            'input_state' => 'IL',
            'input_postal' => '62701',
            'input_country' => 'US',
            // Validated output data (denormalized)
            'output_address_1' => '123 MAIN ST',
            'output_address_2' => 'STE 100',
            'output_city' => 'SPRINGFIELD',
            'output_state' => 'IL',
            'output_postal' => '62701',
            'output_postal_ext' => '1234',
            'output_country' => 'US',
            'validation_status' => 'valid',
            'is_residential' => false,
            'classification' => 'commercial',
            'confidence_score' => 0.95,
            'validated_by_carrier_id' => $this->carrier->id,
            'validated_at' => now(),
            // Extra data stored as JSON
            'extra_data' => ['extra_1' => 'Custom Data 1', 'extra_5' => 'Custom Data 5'],
        ]);
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
        $address = Address::factory()->validated()->create([
            'output_city' => 'TESTCITY',
            'validated_by_carrier_id' => $carrier->id,
        ]);

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
        $address = Address::factory()->validated()->create([
            'external_reference' => 'TEST-001',
            'output_city' => 'TESTCITY',
            'validated_by_carrier_id' => $carrier->id,
        ]);

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
    function appendedFields(ProcessExportBatch $job, array $existingHeaders = []): array
    {
        $method = (new ReflectionClass($job))->getMethod('getValidationFieldsToAppend');
        $method->setAccessible(true);

        return array_column($method->invoke($job, $existingHeaders), 'field');
    }

    it('appends the full BestWay service-result set (rest go in existing columns)', function () {
        $job = new ProcessExportBatch(batch: ImportBatch::factory()->create(), appendValidationFields: true);

        $fieldNames = appendedFields($job);

        expect($fieldNames)->toBe(['ship_via_service', 'ship_via_days', 'ship_via_meets_deadline', 'bestway_optimized', 'ship_method_comment'])
            // These now populate the file's existing columns, not appended ones.
            ->not->toContain('validation_status')
            ->not->toContain('change_summary')
            ->not->toContain('fastest_service')
            ->not->toContain('recommended_ship_date');
    });

    it('skips appended columns the file already carries', function () {
        $job = new ProcessExportBatch(batch: ImportBatch::factory()->create(), appendValidationFields: true);

        // File already has "Ship Via Transit Days" + "ShipMethodComment" columns (any casing/spacing).
        $fieldNames = appendedFields($job, ['ship_via_transit_days', 'ShipMethodComment']);

        expect($fieldNames)->toBe(['ship_via_service', 'ship_via_meets_deadline', 'bestway_optimized']);
    });
});

describe('Address change summary', function () {
    it('is empty when nothing changed', function () {
        $address = Address::factory()->create([
            'input_address_1' => '123 Main St',
            'input_address_2' => null,
            'input_city' => 'Springfield',
            'input_state' => 'IL',
            'input_postal' => '62701',
            'output_address_1' => '123 Main St',
            'output_address_2' => null,
            'output_city' => 'Springfield',
            'output_state' => 'IL',
            'output_postal' => '62701',
            'input_is_residential' => null,
            'is_residential' => null,
            'bestway_optimized' => false,
        ]);

        expect($address->change_summary)->toBe('');
    });

    it('lists only changed fields as "Header: value"', function () {
        $address = Address::factory()->create([
            'input_address_1' => '123 Main St', 'input_address_2' => null,
            'input_city' => 'Springfield', 'input_state' => 'IL', 'input_postal' => '62701',
            'output_address_1' => '123 Main St', 'output_address_2' => null,
            'output_city' => 'Broskeville', 'output_state' => 'IL', 'output_postal' => '62704',
        ]);

        // City + Zip changed, State/Address didn't → only those two listed.
        expect($address->change_summary)->toBe('City: Broskeville, Zip: 62704');
    });

    it('lists just the ZIP when only the ZIP changed', function () {
        $address = Address::factory()->create([
            'input_address_1' => '1 A St', 'input_address_2' => null,
            'input_city' => 'Town', 'input_state' => 'IL', 'input_postal' => '67000',
            'output_address_1' => '1 A St', 'output_address_2' => null,
            'output_city' => 'Town', 'output_state' => 'IL', 'output_postal' => '67152',
        ]);

        expect($address->change_summary)->toBe('Zip: 67152');
    });

    it('is resolvable as an export field', function () {
        $service = new ExportService;
        $address = Address::factory()->create([
            'input_postal' => '62701', 'output_postal' => '62704',
        ]);

        expect($service->getFieldValue($address, 'change_summary'))->toContain('Zip: 62704');
    });
});

describe('ProcessExportBatch append is orthogonal to base mode', function () {
    it('appends service-result columns to a custom template export when requested', function () {
        $carrier = Carrier::factory()->create(['name' => 'UPS', 'slug' => 'ups']);
        $batch = ImportBatch::factory()->create(['include_transit_times' => true]);
        Address::factory()->create([
            'import_batch_id' => $batch->id,
            'input_postal' => '62701',
            'output_postal' => '62704',
            'ship_via_service' => 'UPS Ground',
            'validated_by_carrier_id' => $carrier->id,
        ]);

        $template = ExportTemplate::factory()->create([
            'include_header' => true,
            'field_layout' => [
                ['field' => 'corrected_city', 'header' => 'City', 'position' => 1],
            ],
        ]);

        $job = new ProcessExportBatch(
            batch: $batch,
            templateId: $template->id,
            useImportMapping: false,
            appendValidationFields: true,
        );

        $path = tempnam(sys_get_temp_dir(), 'exp').'.csv';
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('exportUsingTemplate');
        $method->setAccessible(true);
        $method->invoke($job, $path, true);

        $rows = array_map('str_getcsv', file($path));
        expect($rows[0])->toContain('City')
            ->toContain('Ship Via Transit Days')
            ->toContain('ShipMethodComment');
        @unlink($path);
    });
});
