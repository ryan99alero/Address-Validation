<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A pre-computed ("materialized") result for one heavy report view, keyed by the
 * report and a signature of the filter state it was built for. MySQL has no
 * materialized views, so a background job rebuilds these rows and the report
 * pages read them instead of re-aggregating the big tables on every page load.
 */
class ReportSnapshot extends Model
{
    protected $fillable = [
        'report_key',
        'signature',
        'payload',
        'row_count',
        'duration_ms',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'row_count' => 'integer',
            'duration_ms' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Stable signature for a filter set, so the same filters always map to the
     * same snapshot regardless of key order.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function signatureFor(array $filters): string
    {
        ksort($filters);

        return md5((string) json_encode($filters));
    }

    /**
     * Upsert the snapshot for a report + filter set.
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, array<string, mixed>>  $data
     */
    public static function store(string $reportKey, array $filters, Collection $data, int $durationMs): self
    {
        return static::updateOrCreate(
            ['report_key' => $reportKey, 'signature' => static::signatureFor($filters)],
            [
                'payload' => $data->values()->all(),
                'row_count' => $data->count(),
                'duration_ms' => $durationMs,
                'generated_at' => now(),
            ],
        );
    }
}
