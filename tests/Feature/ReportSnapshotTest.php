<?php

use App\Models\ReportSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('produces a stable signature regardless of key order', function () {
    expect(ReportSnapshot::signatureFor(['metric' => 'avg', 'year_from' => 2025]))
        ->toBe(ReportSnapshot::signatureFor(['year_from' => 2025, 'metric' => 'avg']));
});

it('distinguishes different filter sets', function () {
    expect(ReportSnapshot::signatureFor(['year_from' => 2025]))
        ->not->toBe(ReportSnapshot::signatureFor(['year_from' => 2026]));
});

it('stores a report payload and reads it back', function () {
    $data = collect([['id' => 0, 'x' => 1], ['id' => 1, 'x' => 2]]);

    $snapshot = ReportSnapshot::store('test_report', ['min' => 5], $data, 42);

    expect($snapshot->row_count)->toBe(2)
        ->and($snapshot->duration_ms)->toBe(42)
        ->and($snapshot->payload)->toBe($data->all())
        ->and($snapshot->generated_at)->not->toBeNull();
});

it('upserts the same report + filters in place rather than duplicating', function () {
    ReportSnapshot::store('test_report', ['min' => 5], collect([['id' => 0]]), 10);
    $second = ReportSnapshot::store('test_report', ['min' => 5], collect([['id' => 0], ['id' => 1]]), 20);

    expect(ReportSnapshot::where('report_key', 'test_report')->count())->toBe(1)
        ->and($second->row_count)->toBe(2);
});
