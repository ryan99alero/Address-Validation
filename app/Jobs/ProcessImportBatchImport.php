<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\ImportBatch;
use App\Models\ShipViaCode;
use Carbon\Carbon;
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

            Log::info('ProcessImportBatchImport: Completed', [
                'batch_id' => $this->batch->id,
                'successful' => $successCount,
                'failed' => $failedCount,
            ]);

            // Auto-start validation if enabled and we have successful rows
            if ($this->autoValidate && $successCount > 0) {
                // Keep status as PROCESSING since validation will continue
                $this->batch->update([
                    'total_rows' => $actualTotalRows,
                    'processed_rows' => $actualTotalRows,
                    'successful_rows' => $successCount,
                    'failed_rows' => $failedCount,
                    'processing_phase' => ImportBatch::PHASE_VALIDATING,
                ]);

                ProcessImportBatchValidation::dispatch($this->batch);
            } else {
                // No validation - mark as completed
                $this->batch->update([
                    'total_rows' => $actualTotalRows,
                    'processed_rows' => $actualTotalRows,
                    'successful_rows' => $successCount,
                    'failed_rows' => $failedCount,
                    'status' => ImportBatch::STATUS_COMPLETED,
                    'processing_phase' => ImportBatch::PHASE_COMPLETE,
                    'completed_at' => now(),
                ]);
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

                // Sanitize date fields - handle Excel formulas and invalid values
                $addressData = $this->sanitizeDateFields($addressData);

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

    /**
     * Sanitize date fields to handle Excel formulas and invalid values.
     *
     * Excel formulas (e.g., =AU358+6) should be skipped rather than throwing errors.
     * Also handles Excel serial dates and various date string formats.
     */
    protected function sanitizeDateFields(array $addressData): array
    {
        $dateFields = ['requested_ship_date', 'required_on_site_date'];

        foreach ($dateFields as $field) {
            if (! isset($addressData[$field]) || $addressData[$field] === null || $addressData[$field] === '') {
                unset($addressData[$field]);

                continue;
            }

            $value = $addressData[$field];

            // Skip Excel formulas (start with =)
            if (is_string($value) && str_starts_with($value, '=')) {
                unset($addressData[$field]);

                continue;
            }

            // Handle numeric values (Excel serial dates)
            if (is_numeric($value)) {
                try {
                    // Excel serial date: days since 1900-01-01 (with a bug for 1900 leap year)
                    $excelBase = Carbon::createFromDate(1899, 12, 30);
                    $date = $excelBase->copy()->addDays((int) $value);

                    // Sanity check: date should be reasonable (1900-2100)
                    if ($date->year >= 1900 && $date->year <= 2100) {
                        $addressData[$field] = $date->toDateString();
                    } else {
                        unset($addressData[$field]);
                    }
                } catch (\Exception $e) {
                    unset($addressData[$field]);
                }

                continue;
            }

            // Try to parse string dates
            if (is_string($value)) {
                try {
                    $date = Carbon::parse($value);
                    // Sanity check
                    if ($date->year >= 1900 && $date->year <= 2100) {
                        $addressData[$field] = $date->toDateString();
                    } else {
                        unset($addressData[$field]);
                    }
                } catch (\Exception $e) {
                    // Can't parse - skip this field
                    unset($addressData[$field]);
                }

                continue;
            }

            // If we get here with an unexpected type, skip it
            unset($addressData[$field]);
        }

        return $addressData;
    }
}
