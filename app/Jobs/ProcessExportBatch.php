<?php

namespace App\Jobs;

use App\Models\ExportTemplate;
use App\Models\ImportBatch;
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

    public int $timeout = 3600;

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
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        DB::disableQueryLog();

        Log::info('ProcessExportBatch: Starting', [
            'batch_id' => $this->batch->id,
            'use_import_mapping' => $this->useImportMapping,
            'filter_status' => $this->filterStatus,
        ]);

        $this->batch->resetExportProgress();

        try {
            $filename = $this->filename ?? $this->batch->display_name.'_validated_'.now()->format('Ymd_His');
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
            $filePath = 'exports/'.$filename.'.csv';

            Storage::disk('local')->makeDirectory('exports');
            $fullPath = Storage::disk('local')->path($filePath);

            if ($this->appendValidationFields) {
                $this->exportWithValidationFields($fullPath);
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

            $this->batch->update(['export_status' => 'failed']);

            throw $e;
        }
    }

    /**
     * Export using the import field mappings - FAST with denormalized schema.
     */
    protected function exportUsingImportMapping(string $filePath): void
    {
        $mappings = $this->batch->field_mappings ?? [];
        usort($mappings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $exportService = app(ExportService::class);

        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write headers
        $headers = array_map(fn ($m) => $m['source'] ?? '', $mappings);
        fputcsv($handle, $headers, ',', '"', '');

        // Set phases
        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);

        // Single query - no joins needed with denormalized schema!
        $query = $this->buildQuery();
        $totalRows = $query->count();

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, $totalRows);

        // Stream results - memory efficient
        $rowCount = 0;
        $chunkSize = 500;

        $query->orderBy($this->getSortColumn(), $this->getSortDirection())
            ->chunk($chunkSize, function ($addresses) use ($handle, $mappings, $exportService, &$rowCount) {
                foreach ($addresses as $address) {
                    $row = [];
                    foreach ($mappings as $mapping) {
                        $target = $mapping['target'] ?? '';
                        $row[] = empty($target) ? '' : ($exportService->getExportFieldValue($address, $target) ?? '');
                    }
                    fputcsv($handle, $row, ',', '"', '');
                    $rowCount++;
                }

                $this->batch->incrementExportProgress(count($addresses));
            });

        fclose($handle);
    }

    /**
     * Export with validation fields appended.
     */
    protected function exportWithValidationFields(string $filePath): void
    {
        $mappings = $this->batch->field_mappings ?? [];
        usort($mappings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $validationFields = $this->getValidationFieldsToAppend();
        $exportService = app(ExportService::class);

        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write headers - original + validation fields
        $headers = array_map(fn ($m) => $m['source'] ?? '', $mappings);
        foreach ($validationFields as $field) {
            $headers[] = $field['header'];
        }
        fputcsv($handle, $headers, ',', '"', '');

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);

        $query = $this->buildQuery();
        $totalRows = $query->count();

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, $totalRows);

        $rowCount = 0;
        $chunkSize = 500;

        $query->orderBy($this->getSortColumn(), $this->getSortDirection())
            ->chunk($chunkSize, function ($addresses) use ($handle, $mappings, $validationFields, $exportService, &$rowCount) {
                foreach ($addresses as $address) {
                    $row = [];

                    // Original mapped fields
                    foreach ($mappings as $mapping) {
                        $target = $mapping['target'] ?? '';
                        $row[] = empty($target) ? '' : ($exportService->getExportFieldValue($address, $target) ?? '');
                    }

                    // Validation fields
                    foreach ($validationFields as $field) {
                        $row[] = $exportService->getFieldValue($address, $field['field']) ?? '';
                    }

                    fputcsv($handle, $row, ',', '"', '');
                    $rowCount++;
                }

                $this->batch->incrementExportProgress(count($addresses));
            });

        fclose($handle);
    }

    /**
     * Export using a custom template.
     */
    protected function exportUsingTemplate(string $filePath): void
    {
        $template = ExportTemplate::find($this->templateId);
        if (! $template) {
            throw new \Exception('Export template not found');
        }

        $exportService = app(ExportService::class);
        $fields = $template->ordered_fields;

        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write headers
        if ($template->include_header) {
            $headers = array_map(fn ($f) => $f['header'] ?? $f['field'], $fields);
            fputcsv($handle, $headers, ',', '"', '');
        }

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);

        $query = $this->buildQuery();
        $totalRows = $query->count();

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, $totalRows);

        $rowCount = 0;
        $chunkSize = 500;

        $query->orderBy($this->getSortColumn(), $this->getSortDirection())
            ->chunk($chunkSize, function ($addresses) use ($handle, $fields, $exportService, &$rowCount) {
                foreach ($addresses as $address) {
                    $row = [];
                    foreach ($fields as $field) {
                        $row[] = $exportService->getFieldValue($address, $field['field']) ?? '';
                    }
                    fputcsv($handle, $row, ',', '"', '');
                    $rowCount++;
                }

                $this->batch->incrementExportProgress(count($addresses));
            });

        fclose($handle);
    }

    /**
     * Build the base query with filters.
     */
    protected function buildQuery()
    {
        $query = $this->batch->addresses();

        // Apply status filter - now directly on addresses table!
        if ($this->filterStatus === 'validated') {
            $query->whereNotNull('validated_at');
        } elseif (in_array($this->filterStatus, ['valid', 'invalid', 'ambiguous', 'pending'])) {
            $query->where('validation_status', $this->filterStatus);
        }

        return $query;
    }

    /**
     * Get sort column based on sortBy parameter.
     */
    protected function getSortColumn(): string
    {
        return match ($this->sortBy) {
            'delivery_date_asc', 'delivery_date_desc' => 'fastest_date',
            'ship_via_code' => 'ship_via_code',
            'state' => 'output_state',
            'postal_code' => 'output_postal',
            default => 'source_row_number',
        };
    }

    /**
     * Get sort direction.
     */
    protected function getSortDirection(): string
    {
        return $this->sortBy === 'delivery_date_desc' ? 'desc' : 'asc';
    }

    /**
     * Get validation fields to append.
     * Note: Corrected address data is already in the original columns via getExportFieldValue().
     *
     * @return array<array{field: string, header: string}>
     */
    protected function getValidationFieldsToAppend(): array
    {
        return [
            // Validation status fields
            ['field' => 'validation_status', 'header' => 'Validation Status'],
            ['field' => 'is_residential', 'header' => 'Is Residential'],
            ['field' => 'classification', 'header' => 'Classification'],
            ['field' => 'carrier', 'header' => 'Carrier Used'],
            // Transit data fields
            ['field' => 'ship_via_service', 'header' => 'Ship Via Service'],
            ['field' => 'ship_via_days', 'header' => 'Ship Via Transit Days'],
            ['field' => 'ship_via_date', 'header' => 'Ship Via Delivery Date'],
            ['field' => 'ship_via_meets_deadline', 'header' => 'Ship Via Meets Deadline'],
            ['field' => 'fastest_service', 'header' => 'Fastest Service'],
            ['field' => 'fastest_date', 'header' => 'Fastest Delivery Date'],
            ['field' => 'ground_service', 'header' => 'Ground Service'],
            ['field' => 'ground_date', 'header' => 'Ground Delivery Date'],
            ['field' => 'distance_miles', 'header' => 'Distance (Miles)'],
            // BestWay optimization fields
            ['field' => 'previous_ship_via_code', 'header' => 'Previous Ship Via Code'],
            ['field' => 'bestway_optimized', 'header' => 'BestWay Optimized'],
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessExportBatch: Job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update(['export_status' => 'failed']);
    }
}
