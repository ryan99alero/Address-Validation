<?php

use App\Jobs\ProcessExportBatch;
use App\Models\Address;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function exportJob(): ProcessExportBatch
{
    $batch = ImportBatch::create(['name' => 'E', 'original_filename' => 'e.csv', 'file_path' => 'e.csv', 'status' => 'completed']);

    return new ProcessExportBatch($batch);
}

function overwrite(ProcessExportBatch $job, Address $a, string $header): ?string
{
    return (fn () => $this->computedColumnValue($a, $header))->call($job);
}

test('a re-export overwrites existing service-result columns in place with the current finding', function () {
    $job = exportJob();
    $a = new Address([
        'bestway_optimized' => true, 'ship_via_days' => 2, 'ship_via_service' => 'UPS Ground',
        'ship_via_meets_deadline' => true, 'ship_via_code' => '5090',
    ]);

    expect(overwrite($job, $a, 'BestWay Optimized'))->toBe('Yes')
        ->and(overwrite($job, $a, 'Ship Via Transit Days'))->toBe('2')
        ->and(overwrite($job, $a, 'Ship Via Service'))->toBe('UPS Ground')
        ->and(overwrite($job, $a, 'Ship Via Meets Deadline'))->toBe('Yes')
        // header normalization ignores case/spacing/underscores
        ->and(overwrite($job, $a, 'ship_via_transit_days'))->toBe('2');
});

test('an empty finding blanks the column on re-export (never leaves a stale value)', function () {
    $job = exportJob();
    $a = new Address([
        'bestway_optimized' => null, 'ship_via_days' => null, 'ship_via_service' => null,
        'ship_via_meets_deadline' => null,
    ]);

    expect(overwrite($job, $a, 'BestWay Optimized'))->toBe('')
        ->and(overwrite($job, $a, 'Ship Via Transit Days'))->toBe('')
        ->and(overwrite($job, $a, 'Ship Via Meets Deadline'))->toBe('');
});

test('No/false BestWay + not-on-time render as No, not blank', function () {
    $job = exportJob();
    $a = new Address(['bestway_optimized' => false, 'ship_via_meets_deadline' => false]);

    expect(overwrite($job, $a, 'BestWay Optimized'))->toBe('No')
        ->and(overwrite($job, $a, 'Ship Via Meets Deadline'))->toBe('No');
});

test('a non-service column is left untouched (returns null so the imported value is kept)', function () {
    $job = exportJob();
    expect(overwrite($job, new Address(['input_name' => 'Bob']), 'Customer Name'))->toBeNull();
});
