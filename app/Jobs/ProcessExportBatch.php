<?php

namespace App\Jobs;

use App\Models\Address;
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
        public bool $appendValidationFields = false,
        public string $filterSource = 'all'
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

            if ($this->useImportMapping) {
                $this->exportUsingImportMapping($fullPath, $this->appendValidationFields);
            } else {
                $this->exportUsingTemplate($fullPath, $this->appendValidationFields);
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
     * When $appendServiceResults is true, the transit/BestWay result columns are
     * appended after the original mapped columns.
     */
    protected function exportUsingImportMapping(string $filePath, bool $appendServiceResults = false): void
    {
        $mappings = $this->batch->field_mappings ?? [];
        usort($mappings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $sourceHeaders = array_map(fn ($m) => $m['source'] ?? '', $mappings);
        $serviceFields = $appendServiceResults ? $this->getValidationFieldsToAppend($sourceHeaders) : [];
        $exportService = app(ExportService::class);

        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write headers - original mapped columns + only the appended columns the
        // file doesn't already carry.
        $headers = $sourceHeaders;
        foreach ($serviceFields as $field) {
            $headers[] = $field['header'];
        }
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
            ->chunk($chunkSize, function ($addresses) use ($handle, $mappings, $serviceFields, $exportService, $appendServiceResults, &$rowCount) {
                foreach ($addresses as $address) {
                    $row = [];
                    foreach ($mappings as $mapping) {
                        // When surfacing results, known columns (ShipDate, ShipViaCode,
                        // ResidentialDelivery, AddressCleansing*) are populated in-place
                        // with the computed value instead of echoing the imported one.
                        $override = $appendServiceResults
                            ? $this->computedColumnValue($address, $mapping['source'] ?? '')
                            : null;

                        if ($override !== null) {
                            $row[] = $override;
                        } else {
                            $target = $mapping['target'] ?? '';
                            $row[] = empty($target) ? '' : ($exportService->getExportFieldValue($address, $target) ?? '');
                        }
                    }
                    foreach ($serviceFields as $field) {
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
     * When $appendServiceResults is true, the transit/BestWay result columns are
     * appended after the template's own columns (skipping any already present).
     */
    protected function exportUsingTemplate(string $filePath, bool $appendServiceResults = false): void
    {
        $template = ExportTemplate::find($this->templateId);
        if (! $template) {
            throw new \Exception('Export template not found');
        }

        $exportService = app(ExportService::class);
        $fields = $template->ordered_fields;

        // Only append service-result columns the template doesn't already emit.
        $templateFieldKeys = array_column($fields, 'field');
        $serviceFields = $appendServiceResults
            ? array_values(array_filter(
                $this->getValidationFieldsToAppend(),
                fn ($f) => ! in_array($f['field'], $templateFieldKeys, true)
            ))
            : [];

        $handle = fopen($filePath, 'w');
        if (! $handle) {
            throw new \Exception('Could not open export file for writing');
        }

        // Write headers
        if ($template->include_header) {
            $headers = array_map(fn ($f) => $f['header'] ?? $f['field'], $fields);
            foreach ($serviceFields as $field) {
                $headers[] = $field['header'];
            }
            fputcsv($handle, $headers, ',', '"', '');
        }

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_LOADING);

        $query = $this->buildQuery();
        $totalRows = $query->count();

        $this->batch->setExportPhase(ImportBatch::EXPORT_PHASE_WRITING, $totalRows);

        $rowCount = 0;
        $chunkSize = 500;

        $query->orderBy($this->getSortColumn(), $this->getSortDirection())
            ->chunk($chunkSize, function ($addresses) use ($handle, $fields, $serviceFields, $exportService, &$rowCount) {
                foreach ($addresses as $address) {
                    $row = [];
                    foreach ($fields as $field) {
                        $row[] = $exportService->getFieldValue($address, $field['field']) ?? '';
                    }
                    foreach ($serviceFields as $field) {
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

        // Apply validation-source filter (e.g. only addresses resolved from the
        // local invoice cache, or only those from a carrier API).
        if ($this->filterSource === 'local_cache') {
            $query->where('validation_source', 'local_cache');
        } elseif ($this->filterSource === 'api') {
            $query->whereNotNull('validation_source')->where('validation_source', '!=', 'local_cache');
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
     * Columns appended after the file's own columns when surfacing results. Most
     * results are written IN-PLACE into existing columns (see computedColumnValue);
     * only these three are genuinely new — a sortable transit-days number, the
     * BestWay flag, and a readable ship-method recap. Any that the file already
     * carries (by header name) are skipped so nothing is duplicated.
     *
     * @param  array<int, string>  $existingHeaders
     * @return array<array{field: string, header: string}>
     */
    protected function getValidationFieldsToAppend(array $existingHeaders = []): array
    {
        $have = array_map(fn ($h) => $this->normalizeHeader($h), $existingHeaders);

        $fields = [
            ['field' => 'ship_via_days', 'header' => 'Ship Via Transit Days'],
            ['field' => 'bestway_optimized', 'header' => 'BestWay Optimized'],
            ['field' => 'ship_method_comment', 'header' => 'ShipMethodComment'],
        ];

        return array_values(array_filter(
            $fields,
            fn ($f) => ! in_array($this->normalizeHeader($f['header']), $have, true)
        ));
    }

    /**
     * For a handful of well-known columns, the export writes the COMPUTED result
     * into the file's existing column instead of echoing the imported value.
     * Returns null for any other column (use the normal mapped value). Matched on
     * the source header name, case/space/underscore-insensitive.
     */
    protected function computedColumnValue(Address $address, string $sourceHeader): ?string
    {
        return match ($this->normalizeHeader($sourceHeader)) {
            'shipdate' => ($address->recommended_ship_date ?? $address->requested_ship_date)?->format('m/d/Y') ?? '',
            'shipviacode' => (string) ($address->ship_via_code ?? ''),
            'residentialdelivery' => $address->is_residential === null ? '' : ($address->is_residential ? 'Y' : 'N'),
            'addresscleansingcomment' => $address->change_summary,
            'addresscleansingreconciled' => (string) ($address->validation_status ?? ''),
            'shipmethodcomment' => $address->ship_method_comment,
            default => null,
        };
    }

    /**
     * Normalize a header for matching: lowercase, strip spaces/underscores/hyphens.
     */
    protected function normalizeHeader(string $header): string
    {
        return preg_replace('/[\s_\-]+/', '', strtolower(trim($header)));
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
