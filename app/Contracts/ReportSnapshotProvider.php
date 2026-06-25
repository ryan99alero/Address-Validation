<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * A report page that can be pre-computed into a ReportSnapshot. The three static
 * members let the background refresh command build the report without a Livewire
 * request: it asks for the default filters and runs the pure computation.
 */
interface ReportSnapshotProvider
{
    /**
     * Stable key identifying this report's snapshots (e.g. 'carrier_comparison').
     */
    public static function reportKey(): string;

    /**
     * The filter set the landing view uses, so the background job pre-builds the
     * exact snapshot a fresh visitor will read.
     *
     * @return array<string, mixed>
     */
    public static function defaultFilters(): array;

    /**
     * Pure computation of the report rows for a given filter set — no Livewire or
     * request state, so it is callable from the scheduler/queue.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function computeData(array $filters): Collection;
}
