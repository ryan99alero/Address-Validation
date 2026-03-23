<?php

use App\Models\Address;
use App\Models\Carrier;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use App\Services\ExportService;

describe('Export Performance Benchmark', function () {
    beforeEach(function () {
        $this->carrier = Carrier::factory()->create([
            'name' => 'FedEx',
            'slug' => 'fedex',
        ]);

        $this->batch = ImportBatch::factory()->create([
            'carrier_id' => $this->carrier->id,
            'status' => ImportBatch::STATUS_COMPLETED,
        ]);

        $this->template = ExportTemplate::factory()->create([
            'include_header' => true,
            'field_layout' => [
                ['field' => 'external_reference', 'header' => 'RefNum', 'position' => 1],
                ['field' => 'name', 'header' => 'Name', 'position' => 2],
                ['field' => 'company', 'header' => 'Company', 'position' => 3],
                ['field' => 'original_address_line_1', 'header' => 'OrigAddr1', 'position' => 4],
                ['field' => 'original_city', 'header' => 'OrigCity', 'position' => 5],
                ['field' => 'corrected_address_line_1', 'header' => 'CorrAddr1', 'position' => 6],
                ['field' => 'corrected_city', 'header' => 'CorrCity', 'position' => 7],
                ['field' => 'corrected_state', 'header' => 'CorrState', 'position' => 8],
                ['field' => 'corrected_postal_code', 'header' => 'CorrZip', 'position' => 9],
                ['field' => 'validation_status', 'header' => 'Status', 'position' => 10],
                ['field' => 'is_residential', 'header' => 'Residential', 'position' => 11],
                ['field' => 'confidence_score', 'header' => 'Confidence', 'position' => 12],
                ['field' => 'carrier', 'header' => 'Carrier', 'position' => 13],
                ['field' => 'fastest_service', 'header' => 'FastestSvc', 'position' => 14],
                ['field' => 'extra_1', 'header' => 'Extra1', 'position' => 15],
                ['field' => 'extra_2', 'header' => 'Extra2', 'position' => 16],
            ],
        ]);

        $this->service = new ExportService;
    });

    it('benchmarks export of 100 addresses', function () {
        // Create 100 validated addresses with full data
        $addresses = Address::factory()
            ->count(100)
            ->validated()
            ->create([
                'import_batch_id' => $this->batch->id,
                'validated_by_carrier_id' => $this->carrier->id,
                'extra_data' => ['extra_1' => 'Custom1', 'extra_2' => 'Custom2'],
            ]);

        // Warm up
        $this->service->getExportData($addresses->take(10), $this->template);

        // Benchmark
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $data = $this->service->getExportData($addresses, $this->template);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $duration = ($endTime - $startTime) * 1000; // ms
        $memoryUsed = ($endMemory - $startMemory) / 1024; // KB

        // Output results
        dump([
            'records' => 100,
            'duration_ms' => round($duration, 2),
            'memory_kb' => round($memoryUsed, 2),
            'rows_generated' => count($data),
            'ms_per_record' => round($duration / 100, 3),
        ]);

        // Assertions
        expect(count($data))->toBe(101); // 100 records + 1 header
        expect($duration)->toBeLessThan(500); // Should complete in under 500ms
    });

    it('benchmarks export of 500 addresses', function () {
        // Create 500 validated addresses
        $addresses = Address::factory()
            ->count(500)
            ->validated()
            ->create([
                'import_batch_id' => $this->batch->id,
                'validated_by_carrier_id' => $this->carrier->id,
                'extra_data' => ['extra_1' => 'Custom1', 'extra_2' => 'Custom2'],
            ]);

        // Benchmark
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $data = $this->service->getExportData($addresses, $this->template);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $duration = ($endTime - $startTime) * 1000;
        $memoryUsed = ($endMemory - $startMemory) / 1024;

        dump([
            'records' => 500,
            'duration_ms' => round($duration, 2),
            'memory_kb' => round($memoryUsed, 2),
            'rows_generated' => count($data),
            'ms_per_record' => round($duration / 500, 3),
        ]);

        expect(count($data))->toBe(501);
        expect($duration)->toBeLessThan(2000); // Should complete in under 2s
    });

    it('benchmarks export of 1000 addresses', function () {
        // Create 1000 validated addresses
        $addresses = Address::factory()
            ->count(1000)
            ->validated()
            ->create([
                'import_batch_id' => $this->batch->id,
                'validated_by_carrier_id' => $this->carrier->id,
                'extra_data' => ['extra_1' => 'Data1', 'extra_2' => 'Data2'],
            ]);

        // Benchmark
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $data = $this->service->getExportData($addresses, $this->template);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $duration = ($endTime - $startTime) * 1000;
        $memoryUsed = ($endMemory - $startMemory) / 1024;

        dump([
            'records' => 1000,
            'duration_ms' => round($duration, 2),
            'memory_kb' => round($memoryUsed, 2),
            'rows_generated' => count($data),
            'ms_per_record' => round($duration / 1000, 3),
        ]);

        expect(count($data))->toBe(1001);
        expect($duration)->toBeLessThan(5000); // Should complete in under 5s
    });

    it('benchmarks database query time separately', function () {
        // Create 1000 addresses
        Address::factory()
            ->count(1000)
            ->validated()
            ->create([
                'import_batch_id' => $this->batch->id,
                'validated_by_carrier_id' => $this->carrier->id,
            ]);

        // Benchmark query only (denormalized - single table)
        $startTime = microtime(true);

        $addresses = Address::query()
            ->where('import_batch_id', $this->batch->id)
            ->with('validatedByCarrier') // Only need carrier name
            ->get();

        $queryTime = (microtime(true) - $startTime) * 1000;

        // Benchmark export processing
        $startTime = microtime(true);
        $data = $this->service->getExportData($addresses, $this->template);
        $processTime = (microtime(true) - $startTime) * 1000;

        dump([
            'records' => 1000,
            'query_time_ms' => round($queryTime, 2),
            'process_time_ms' => round($processTime, 2),
            'total_ms' => round($queryTime + $processTime, 2),
            'query_percentage' => round(($queryTime / ($queryTime + $processTime)) * 100, 1).'%',
        ]);

        expect(count($addresses))->toBe(1000);
        expect($queryTime)->toBeLessThan(500); // Query should be fast
    });
});
