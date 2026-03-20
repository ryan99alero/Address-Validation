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
        public string $sortBy = 'original',
        public bool $appendValidationFields = false
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
            'append_validation_fields' => $this->appendValidationFields,
            'filter_status' => $this->filterStatus,
            'sort_by' => $this->sortBy,
            'template_id' => $this->templateId,
        ]);

        // Reset export progress tracking
        $this->batch->resetExportProgress();

        try {
            // Generate filename
            $filename = $this->filename ?? $this->batch->display_name.'_validated_'.now()->format('Ymd_His');
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
            $filePath = 'exports/'.$filename.'.csv';

            // Ensure exports directory exists
            Storage::disk('local')->makeDirectory('exports');

            $fullPath = Storage::disk('local')->path($filePath);

            if ($this->appendValidationFields) {
                $this->exportUsingImportMappingWithValidation($fullPath);
            } elseif ($this->useImportMapping) {
                $this->exportUsingImportMapping($fullPath);
            } else {
                $this->exportUsingTemplate($fullPath);
            }

            $this->batch->update([
                'export_file_path' => $filePath,
                'export_status' => 'completed',
                'export_phase' => ImportBatch::EXPORT_PHASE_COMPLETE,
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
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);
        $addressIds = $this->getFilteredAddressIds();

        // Set total rows for progress tracking
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, count($addressIds));

        // Process in chunks using just IDs - much more memory efficient
        $rowCount = 0;
        $chunkSize = 200;

        foreach (array_chunk($addressIds, $chunkSize) as $idChunk) {
            // Reconnect to ensure fresh connection (prevents timeout between chunks)
            DB::reconnect();

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

            // Update progress after each chunk
            $this->batch->refresh();
            $this->batch->incrementExportProgress(count($idChunk));

            // Clear memory
            unset($addresses, $corrections);
            gc_collect_cycles();
        }

        fclose($handle);
    }

    /**
     * Export using import field mappings PLUS append validation/correction fields.
     * This keeps the original file structure and adds new columns at the end.
     */
    protected function exportUsingImportMappingWithValidation(string $filePath): void
    {
        $mappings = $this->batch->field_mappings ?? [];

        // Sort mappings by position
        usort($mappings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        // Define validation fields to append
        $validationFields = $this->getValidationFieldsToAppend();

        $exportService = app(ExportService::class);

        // Open file for writing
        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write header row - original headers + validation headers
        $headers = [];
        foreach ($mappings as $mapping) {
            $headers[] = $mapping['source'] ?? '';
        }
        foreach ($validationFields as $field) {
            $headers[] = $field['header'];
        }
        fputcsv($handle, $headers, ',', '"', '');

        // Get address IDs that match the filter
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);
        $addressIds = $this->getFilteredAddressIds();

        // Set total rows for progress tracking
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, count($addressIds));

        // Process in chunks
        $rowCount = 0;
        $chunkSize = 200;

        foreach (array_chunk($addressIds, $chunkSize) as $idChunk) {
            // Reconnect to ensure fresh connection (prevents timeout between chunks)
            DB::reconnect();

            // Load addresses - eager load shipViaCodeRecord if transit times enabled
            $addressQuery = Address::whereIn('id', $idChunk);
            if ($this->batch->include_transit_times) {
                $addressQuery->with('shipViaCodeRecord');
            }
            $addresses = $addressQuery->get();

            // Load corrections separately
            $addressIdList = $addresses->pluck('id')->toArray();
            $corrections = AddressCorrection::whereIn('address_id', $addressIdList)
                ->with('carrier')
                ->orderBy('validated_at', 'desc')
                ->get()
                ->groupBy('address_id')
                ->map(fn ($group) => $group->first());

            // Load transit times if batch has them enabled
            $transitTimes = collect();
            if ($this->batch->include_transit_times) {
                $transitTimes = TransitTime::whereIn('address_id', $addressIdList)
                    ->get()
                    ->groupBy('address_id');
            }

            foreach ($addresses as $address) {

                $address->setRelation('latestCorrection', $corrections->get($address->id));

                if ($this->batch->include_transit_times) {
                    $address->setRelation('transitTimes', $transitTimes->get($address->id, collect()));
                }

                // Build row: original mapped fields + validation fields
                $row = [];

                // Original import fields
                foreach ($mappings as $mapping) {
                    $target = $mapping['target'] ?? '';
                    if (empty($target)) {
                        $row[] = '';
                    } else {
                        $row[] = $exportService->getExportFieldValue($address, $target) ?? '';
                    }
                }

                // Append validation fields
                foreach ($validationFields as $field) {
                    $row[] = $exportService->getFieldValue($address, $field['field']) ?? '';
                }

                fputcsv($handle, $row, ',', '"', '');
                $rowCount++;
            }

            // Update progress after each chunk
            $this->batch->refresh();
            $this->batch->incrementExportProgress(count($idChunk));

            // Clear memory
            unset($addresses, $corrections, $transitTimes);
            gc_collect_cycles();
        }

        fclose($handle);
    }

    /**
     * Get the validation fields to append based on batch configuration.
     *
     * @return array<array{field: string, header: string}>
     */
    protected function getValidationFieldsToAppend(): array
    {
        // Core validation fields always included
        $fields = [
            ['field' => 'corrected_address_line_1', 'header' => 'Corrected Address Line 1'],
            ['field' => 'corrected_address_line_2', 'header' => 'Corrected Address Line 2'],
            ['field' => 'corrected_city', 'header' => 'Corrected City'],
            ['field' => 'corrected_state', 'header' => 'Corrected State'],
            ['field' => 'corrected_postal_code', 'header' => 'Corrected Postal Code'],
            ['field' => 'corrected_postal_code_ext', 'header' => 'Corrected ZIP+4'],
            ['field' => 'validation_status', 'header' => 'Validation Status'],
            ['field' => 'is_residential', 'header' => 'Is Residential'],
            ['field' => 'classification', 'header' => 'Classification'],
            ['field' => 'carrier', 'header' => 'Carrier Used'],
        ];

        // Add transit time fields if enabled for this batch
        if ($this->batch->include_transit_times) {
            $transitFields = [
                ['field' => 'ship_via_service', 'header' => 'Ship Via Service'],
                ['field' => 'ship_via_transit_days', 'header' => 'Ship Via Transit Days'],
                ['field' => 'ship_via_delivery_date', 'header' => 'Ship Via Delivery Date'],
                ['field' => 'ship_via_meets_deadline', 'header' => 'Ship Via Meets Deadline'],
                ['field' => 'recommended_service', 'header' => 'Recommended Service'],
                ['field' => 'estimated_delivery_date', 'header' => 'Estimated Delivery Date'],
                ['field' => 'can_meet_required_date', 'header' => 'Can Meet Required Date'],
                ['field' => 'suggested_service', 'header' => 'Suggested Service'],
                ['field' => 'suggested_delivery_date', 'header' => 'Suggested Delivery Date'],
                ['field' => 'fastest_service', 'header' => 'Fastest Service'],
                ['field' => 'fastest_delivery_date', 'header' => 'Fastest Delivery Date'],
                ['field' => 'distance_miles', 'header' => 'Distance (Miles)'],
            ];

            $fields = array_merge($fields, $transitFields);
        }

        return $fields;
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
        // Transit-related fields include: transit_*, ship_via_*, fastest_*, distance_miles
        $needsTransitTimes = collect($fields)->contains(function ($field) {
            $fieldName = $field['field'] ?? '';

            return str_starts_with($fieldName, 'transit_')
                || str_starts_with($fieldName, 'ship_via_')
                || str_starts_with($fieldName, 'fastest_')
                || $fieldName === 'distance_miles';
        });

        // Check if ship_via_code fields need the shipViaCodeRecord relation
        $needsShipViaCode = collect($fields)->contains(function ($field) {
            $fieldName = $field['field'] ?? '';

            return str_starts_with($fieldName, 'ship_via_');
        });

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
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);
        $addressIds = $this->getFilteredAddressIds();

        // Set total rows for progress tracking
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, count($addressIds));

        // Process in chunks
        $rowCount = 0;
        $chunkSize = 200;

        foreach (array_chunk($addressIds, $chunkSize) as $idChunk) {
            // Reconnect to ensure fresh connection (prevents timeout between chunks)
            DB::reconnect();

            // Load addresses - eager load shipViaCodeRecord if needed
            $addressQuery = Address::whereIn('id', $idChunk);
            if ($needsShipViaCode) {
                $addressQuery->with('shipViaCodeRecord');
            }
            $addresses = $addressQuery->get();

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

            // Update progress after each chunk
            $this->batch->refresh();
            $this->batch->incrementExportProgress(count($idChunk));

            // Clear memory
            unset($addresses, $corrections, $transitTimes);
            gc_collect_cycles();
        }

        fclose($handle);
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
