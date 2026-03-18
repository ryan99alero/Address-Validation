<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\ExportTemplate;
use App\Models\ImportBatch;
use App\Models\TransitTime;
use App\Services\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Telescope\Telescope;

class ProcessExportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600; // 1 hour max

    public function __construct(
        public ImportBatch $batch,
        public ?int $templateId = null,
        public bool $useImportMapping = true,
        public string $filterStatus = 'all',
        public ?string $filename = null,
        public string $sortBy = 'original'
    ) {}

    public function handle(): void
    {
        // Disable Telescope for this job - it stores all queries in memory
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        // Disable query log to save memory
        DB::disableQueryLog();

        Log::info('ProcessExportBatch: Starting', [
            'batch_id' => $this->batch->id,
            'use_import_mapping' => $this->useImportMapping,
            'filter_status' => $this->filterStatus,
        ]);

        $this->batch->update([
            'export_status' => 'processing',
        ]);

        try {
            // Generate filename
            $filename = $this->filename ?? $this->batch->display_name.'_validated_'.now()->format('Ymd_His');
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
            $filePath = 'exports/'.$filename.'.csv';

            // Ensure exports directory exists
            Storage::disk('local')->makeDirectory('exports');

            $fullPath = Storage::disk('local')->path($filePath);

            if ($this->useImportMapping) {
                $this->exportUsingImportMapping($fullPath);
            } else {
                $this->exportUsingTemplate($fullPath);
            }

            $this->batch->update([
                'export_file_path' => $filePath,
                'export_status' => 'completed',
                'export_completed_at' => now(),
            ]);

            Log::info('ProcessExportBatch: Completed', [
                'batch_id' => $this->batch->id,
                'file_path' => $filePath,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessExportBatch: Failed', [
                'batch_id' => $this->batch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->batch->update([
                'export_status' => 'failed',
            ]);

            throw $e;
        }
    }

    /**
     * Export using the import field mappings - streams directly to CSV.
     */
    protected function exportUsingImportMapping(string $filePath): void
    {
        $mappings = $this->batch->field_mappings ?? [];

        // Sort mappings by position
        usort($mappings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $exportService = app(ExportService::class);

        // Open file for writing
        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write header row
        $headers = [];
        foreach ($mappings as $mapping) {
            $headers[] = $mapping['source'] ?? '';
        }
        fputcsv($handle, $headers, ',', '"', '');

        // Get address IDs that match the filter first (memory efficient)
        $addressIds = $this->getFilteredAddressIds();

        // Process in chunks using just IDs - much more memory efficient
        $rowCount = 0;
        $chunkSize = 200;
        $memoryWarningThreshold = 800 * 1024 * 1024; // 800MB warning

        foreach (array_chunk($addressIds, $chunkSize) as $idChunk) {
            // Check memory usage before processing chunk
            $memoryUsage = memory_get_usage(true);
            if ($memoryUsage > $memoryWarningThreshold) {
                Log::warning('ProcessExportBatch: High memory usage', [
                    'batch_id' => $this->batch->id,
                    'memory_mb' => round($memoryUsage / 1024 / 1024, 2),
                    'rows_processed' => $rowCount,
                ]);
            }

            // Load just this chunk - avoid eager loading latestCorrection due to complex subquery
            $addresses = Address::whereIn('id', $idChunk)->get();

            // Load corrections separately to avoid ambiguous column issues
            $addressIdList = $addresses->pluck('id')->toArray();
            $corrections = AddressCorrection::whereIn('address_id', $addressIdList)
                ->orderBy('validated_at', 'desc')
                ->get()
                ->groupBy('address_id')
                ->map(fn ($group) => $group->first());

            foreach ($addresses as $address) {
                // Manually attach the latest correction
                $address->setRelation('latestCorrection', $corrections->get($address->id));

                $row = [];
                foreach ($mappings as $mapping) {
                    $target = $mapping['target'] ?? '';
                    if (empty($target)) {
                        $row[] = '';
                    } else {
                        $row[] = $exportService->getExportFieldValue($address, $target) ?? '';
                    }
                }
                fputcsv($handle, $row, ',', '"', '');
                $rowCount++;
            }

            // Explicitly clear references
            unset($addresses, $corrections);
            gc_collect_cycles();
        }

        fclose($handle);

        Log::info('ProcessExportBatch: Wrote rows', [
            'batch_id' => $this->batch->id,
            'rows' => $rowCount,
        ]);
    }

    /**
     * Export using a custom template - streams directly to CSV.
     */
    protected function exportUsingTemplate(string $filePath): void
    {
        $template = ExportTemplate::find($this->templateId);
        if (! $template) {
            throw new \Exception('Export template not found');
        }

        $exportService = app(ExportService::class);
        $fields = $template->ordered_fields;

        // Check if any transit time fields are requested
        $needsTransitTimes = collect($fields)->contains(
            fn ($field) => str_starts_with($field['field'] ?? '', 'transit_')
        );

        // Open file for writing
        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write header row if enabled
        if ($template->include_header) {
            $headers = [];
            foreach ($fields as $field) {
                $headers[] = $field['header'] ?? $field['field'];
            }
            fputcsv($handle, $headers, ',', '"', '');
        }

        // Get address IDs that match the filter first
        $addressIds = $this->getFilteredAddressIds();

        // Process in chunks
        $rowCount = 0;
        $chunkSize = 200;

        foreach (array_chunk($addressIds, $chunkSize) as $idChunk) {
            // Load addresses without eager loading to avoid ambiguous column issues
            $addresses = Address::whereIn('id', $idChunk)->get();

            // Load corrections separately with carrier
            $addressIdList = $addresses->pluck('id')->toArray();
            $corrections = AddressCorrection::whereIn('address_id', $addressIdList)
                ->with('carrier')
                ->orderBy('validated_at', 'desc')
                ->get()
                ->groupBy('address_id')
                ->map(fn ($group) => $group->first());

            // Load transit times if needed
            $transitTimes = collect();
            if ($needsTransitTimes) {
                $transitTimes = TransitTime::whereIn('address_id', $addressIdList)
                    ->get()
                    ->groupBy('address_id');
            }

            foreach ($addresses as $address) {
                // Manually attach the latest correction
                $address->setRelation('latestCorrection', $corrections->get($address->id));

                // Attach transit times if loaded
                if ($needsTransitTimes) {
                    $address->setRelation('transitTimes', $transitTimes->get($address->id, collect()));
                }

                $row = [];
                foreach ($fields as $field) {
                    $row[] = $exportService->getFieldValue($address, $field['field']) ?? '';
                }
                fputcsv($handle, $row, ',', '"', '');
                $rowCount++;
            }

            unset($addresses, $corrections, $transitTimes);
            gc_collect_cycles();
        }

        fclose($handle);

        Log::info('ProcessExportBatch: Wrote rows', [
            'batch_id' => $this->batch->id,
            'rows' => $rowCount,
        ]);
    }

    /**
     * Get filtered address IDs efficiently using a single query.
     *
     * @return array<int>
     */
    protected function getFilteredAddressIds(): array
    {
        $query = $this->batch->addresses()->select('addresses.id');

        // Join corrections for filtering or sorting
        $needsCorrectionsJoin = $this->filterStatus !== 'all' ||
            in_array($this->sortBy, ['delivery_date_asc', 'delivery_date_desc', 'state', 'postal_code']);

        if ($needsCorrectionsJoin) {
            $query->leftJoin('address_corrections as ac', function ($join) {
                $join->on('ac.address_id', '=', 'addresses.id')
                    ->whereRaw('ac.id = (SELECT MAX(id) FROM address_corrections WHERE address_id = addresses.id)');
            });

            // Apply status filter
            if ($this->filterStatus === 'validated') {
                $query->whereNotNull('ac.id');
            } elseif (in_array($this->filterStatus, ['valid', 'invalid', 'ambiguous'])) {
                $query->where('ac.validation_status', $this->filterStatus);
            }
        }

        // Join transit times for delivery date sorting - use MIN to get earliest delivery
        if (in_array($this->sortBy, ['delivery_date_asc', 'delivery_date_desc'])) {
            $query->leftJoin('transit_times as tt', function ($join) {
                $join->on('tt.address_id', '=', 'addresses.id');
            });
        }

        // Use GROUP BY to handle potential duplicates from joins
        $query->groupBy('addresses.id');

        // Apply sorting - add sort columns to select for MySQL compatibility
        match ($this->sortBy) {
            'delivery_date_asc' => $query
                ->selectRaw('MIN(COALESCE(addresses.estimated_delivery_date, tt.delivery_date)) as sort_date')
                ->orderByRaw('sort_date ASC'),
            'delivery_date_desc' => $query
                ->selectRaw('MIN(COALESCE(addresses.estimated_delivery_date, tt.delivery_date)) as sort_date')
                ->orderByRaw('sort_date DESC'),
            'ship_via_code' => $query
                ->addSelect('addresses.ship_via_code')
                ->groupBy('addresses.ship_via_code')
                ->orderBy('addresses.ship_via_code'),
            'state' => $query
                ->selectRaw('COALESCE(ac.corrected_state, addresses.state) as sort_state')
                ->orderByRaw('sort_state'),
            'postal_code' => $query
                ->selectRaw('COALESCE(ac.corrected_postal_code, addresses.postal_code) as sort_postal')
                ->orderByRaw('sort_postal'),
            default => $query
                ->addSelect('addresses.source_row_number')
                ->groupBy('addresses.source_row_number')
                ->orderBy('addresses.source_row_number'),
        };

        return $query->pluck('addresses.id')->toArray();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessExportBatch: Job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update([
            'export_status' => 'failed',
        ]);
    }
}
