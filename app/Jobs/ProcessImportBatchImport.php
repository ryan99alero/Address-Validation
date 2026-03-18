<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\ImportBatch;
use App\Models\ShipViaCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Telescope\Telescope;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;

class ProcessImportBatchImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes max

    public function __construct(
        public ImportBatch $batch,
        public array $mappings,
        public bool $autoValidate = true
    ) {}

    public function handle(): void
    {
        // Disable Telescope for this job - it stores all queries in memory
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        // Disable query log to save memory
        DB::disableQueryLog();

        Log::info('ProcessImportBatchImport: Starting', [
            'batch_id' => $this->batch->id,
            'total_rows' => $this->batch->total_rows,
        ]);

        $this->batch->update([
            'status' => ImportBatch::STATUS_PROCESSING,
            'processing_phase' => ImportBatch::PHASE_IMPORTING,
            'started_at' => now(),
        ]);

        try {
            $filePath = Storage::disk('local')->path($this->batch->file_path);

            // Resolve pass-through fields
            $resolvedMappings = $this->resolvePassThroughFields($this->mappings);
            $this->batch->update(['field_mappings' => $resolvedMappings]);

            // Build position to field map
            $positionToField = $this->buildPositionMap($resolvedMappings);

            // Process in chunks using a custom import class
            $importer = new ChunkedAddressImporter(
                $this->batch,
                $positionToField
            );

            Excel::import($importer, $filePath);

            $successCount = $importer->getSuccessCount();
            $failedCount = $importer->getFailedCount();

            // Update total_rows to actual count (listWorksheetInfo can be inaccurate)
            $actualTotalRows = $successCount + $failedCount;

            $this->batch->update([
                'total_rows' => $actualTotalRows,
                'processed_rows' => $actualTotalRows,
                'successful_rows' => $successCount,
                'failed_rows' => $failedCount,
                'status' => ImportBatch::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            Log::info('ProcessImportBatchImport: Completed', [
                'batch_id' => $this->batch->id,
                'successful' => $successCount,
                'failed' => $failedCount,
            ]);

            // Auto-start validation if enabled
            if ($this->autoValidate && $successCount > 0) {
                ProcessImportBatchValidation::dispatch($this->batch);
            }

        } catch (\Exception $e) {
            Log::error('ProcessImportBatchImport: Failed', [
                'batch_id' => $this->batch->id,
                'error' => $e->getMessage(),
            ]);

            $this->batch->update([
                'status' => ImportBatch::STATUS_FAILED,
            ]);

            throw $e;
        }
    }

    /**
     * Resolve pass-through fields to actual extra_N fields.
     */
    protected function resolvePassThroughFields(array $mappings): array
    {
        $usedExtraFields = [];
        foreach ($mappings as $mapping) {
            $targetField = $mapping['target'] ?? null;
            if ($targetField && str_starts_with($targetField, 'extra_')) {
                $usedExtraFields[] = $targetField;
            }
        }

        $resolvedMappings = [];
        $nextExtraIndex = 1;

        foreach ($mappings as $mapping) {
            $targetField = $mapping['target'] ?? null;

            if ($targetField === '_passthrough') {
                while ($nextExtraIndex <= 20 && in_array("extra_{$nextExtraIndex}", $usedExtraFields, true)) {
                    $nextExtraIndex++;
                }

                if ($nextExtraIndex <= 20) {
                    $mapping['target'] = "extra_{$nextExtraIndex}";
                    $usedExtraFields[] = "extra_{$nextExtraIndex}";
                    $nextExtraIndex++;
                } else {
                    $mapping['target'] = '_skip';
                }
            }

            $resolvedMappings[] = $mapping;
        }

        return $resolvedMappings;
    }

    /**
     * Build position to field mapping.
     */
    protected function buildPositionMap(array $mappings): array
    {
        $positionToField = [];
        foreach ($mappings as $mapping) {
            $position = $mapping['position'] ?? null;
            $targetField = $mapping['target'] ?? null;

            if ($position === null || $targetField === '_skip' || empty($targetField)) {
                continue;
            }

            $positionToField[$position] = $targetField;
        }

        return $positionToField;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessImportBatchImport: Job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update([
            'status' => ImportBatch::STATUS_FAILED,
        ]);
    }
}

/**
 * Chunked importer for memory-efficient Excel processing.
 */
class ChunkedAddressImporter implements ToArray, WithChunkReading, WithHeadingRow
{
    protected int $successCount = 0;

    protected int $failedCount = 0;

    protected int $rowNumber = 1; // Start after header

    public function __construct(
        protected ImportBatch $batch,
        protected array $positionToField
    ) {}

    public function array(array $rows): void
    {
        foreach ($rows as $row) {
            $this->rowNumber++;

            try {
                $addressData = [
                    'import_batch_id' => $this->batch->id,
                    'source' => 'import',
                    'source_row_number' => $this->rowNumber,
                    'created_by' => $this->batch->imported_by,
                ];

                // Map row data using position indices
                $rowValues = array_values($row);
                foreach ($this->positionToField as $position => $field) {
                    $value = $rowValues[$position] ?? null;
                    if ($value !== null && $value !== '') {
                        $addressData[$field] = $value;
                    }
                }

                // Parse address line for suite extraction
                if (! empty($addressData['address_line_1'])) {
                    $addressData = $this->parseAddressLine($addressData);
                }

                // Look up ship_via_code_id if ship_via_code is provided
                if (! empty($addressData['ship_via_code'])) {
                    $shipViaCode = ShipViaCode::lookup($addressData['ship_via_code']);
                    if ($shipViaCode) {
                        $addressData['ship_via_code_id'] = $shipViaCode->id;
                    }
                }

                // Only create if we have address_line_1
                if (! empty($addressData['address_line_1'])) {
                    // Use firstOrCreate to prevent duplicates if job retries
                    $address = Address::firstOrCreate(
                        [
                            'import_batch_id' => $this->batch->id,
                            'source_row_number' => $this->rowNumber,
                        ],
                        $addressData
                    );

                    // Only count as new if it was actually created
                    if ($address->wasRecentlyCreated) {
                        $this->successCount++;
                    }

                    // Update progress every 100 rows
                    if ($this->successCount % 100 === 0) {
                        $this->batch->update(['processed_rows' => $this->successCount]);
                    }
                } else {
                    $this->failedCount++;
                }
            } catch (\Exception $e) {
                $this->failedCount++;
                Log::warning('ProcessImportBatchImport: Row failed', [
                    'batch_id' => $this->batch->id,
                    'row' => $this->rowNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function chunkSize(): int
    {
        return 500; // Process 500 rows at a time
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    /**
     * Parse address line to extract suite/unit info.
     *
     * Only extracts when standard unit abbreviations are found:
     * STE, SUITE, APT, APARTMENT, UNIT, BLDG, BUILDING, FL, FLOOR, RM, ROOM
     *
     * Does NOT extract bare # symbols - those are often route numbers or other identifiers.
     */
    protected function parseAddressLine(array $addressData): array
    {
        $addressLine1 = $addressData['address_line_1'] ?? '';

        if (empty($addressLine1)) {
            return $addressData;
        }

        // Standard unit keywords that indicate a secondary address
        $unitKeywords = 'ste|suite|unit|apt|apartment|bldg|building|fl|floor|rm|room';

        // Pattern 1: Comma followed by unit designation (e.g., "123 Main St, Ste B")
        if (preg_match('/^(.+?),\s*((?:'.$unitKeywords.')\s*[A-Za-z0-9][\w\-&\s]*)$/i', $addressLine1, $matches)) {
            $mainAddress = trim($matches[1]);
            if (strlen($mainAddress) >= 5) {
                $addressData['address_line_1'] = $mainAddress;
                $unit = strtoupper(trim($matches[2]));
                $addressData['address_line_2'] = ! empty($addressData['address_line_2'])
                    ? trim($addressData['address_line_2']).', '.$unit
                    : $unit;
            }

            return $addressData;
        }

        // Pattern 2: Space followed by unit keywords (e.g., "123 Main St Ste B")
        if (preg_match('/^(.+?)\s+('.$unitKeywords.')\s+([A-Za-z0-9][\w\-&\s]*)$/i', $addressLine1, $matches)) {
            $mainAddress = trim($matches[1]);
            if (strlen($mainAddress) >= 5 && preg_match('/\d/', $mainAddress)) {
                $addressData['address_line_1'] = $mainAddress;
                $unit = strtoupper(trim($matches[2])).' '.trim($matches[3]);
                $addressData['address_line_2'] = ! empty($addressData['address_line_2'])
                    ? trim($addressData['address_line_2']).', '.$unit
                    : $unit;
            }

            return $addressData;
        }

        // Pattern 3: Unit keyword followed by # (e.g., "123 Main St Apt #5")
        if (preg_match('/^(.+?)\s+('.$unitKeywords.')\s*#\s*([A-Za-z0-9][\w\-&\s]*)$/i', $addressLine1, $matches)) {
            $mainAddress = trim($matches[1]);
            if (strlen($mainAddress) >= 5 && preg_match('/\d/', $mainAddress)) {
                $addressData['address_line_1'] = $mainAddress;
                $unit = strtoupper(trim($matches[2])).' '.trim($matches[3]);
                $addressData['address_line_2'] = ! empty($addressData['address_line_2'])
                    ? trim($addressData['address_line_2']).', '.$unit
                    : $unit;
            }
        }

        return $addressData;
    }
}
