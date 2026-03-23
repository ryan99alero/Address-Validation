<?php

namespace App\Services;

use App\Models\Address;
use App\Models\CompanySetting;
use App\Models\ImportBatch;
use App\Models\ImportFieldTemplate;
use App\Models\ShipViaCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ImportService
{
    /**
     * Parse headers from an uploaded file - memory efficient.
     *
     * @return array<int, string>
     */
    public function parseHeaders(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // For CSV, just read the first line
        if ($extension === 'csv') {
            $handle = fopen($file->getPathname(), 'r');
            if (! $handle) {
                return [];
            }

            $headers = fgetcsv($handle);
            fclose($handle);

            return $headers ?: [];
        }

        // For Excel files, use PhpSpreadsheet with limited row reading
        try {
            $reader = IOFactory::createReaderForFile($file->getPathname());
            $reader->setReadDataOnly(true);

            // Create a filter to only read the first row
            $reader->setReadFilter(new LimitedRowReadFilter(1, 1));

            $spreadsheet = $reader->load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();

            $headers = [];
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                $headers[] = $cellValue !== null ? (string) $cellValue : '';
            }

            // Clean up memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $headers;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Parse rows from an uploaded file (excluding header).
     * WARNING: This loads the entire file - use only for small files.
     * For large files, use the ProcessImportBatchImport job instead.
     *
     * @return array<int, array<int, mixed>>
     */
    public function parseRows(UploadedFile $file): array
    {
        $data = Excel::toArray([], $file);

        if (empty($data) || empty($data[0])) {
            return [];
        }

        $rows = $data[0];

        // Remove header row
        array_shift($rows);

        return $rows;
    }

    /**
     * Get preview rows (first N rows) - memory efficient.
     *
     * @return array<int, array<int, mixed>>
     */
    public function getPreviewRows(UploadedFile $file, int $count = 5): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // For CSV, read only the first few lines
        if ($extension === 'csv') {
            $handle = fopen($file->getPathname(), 'r');
            if (! $handle) {
                return [];
            }

            $rows = [];
            $lineNum = 0;
            while (($row = fgetcsv($handle)) !== false && $lineNum <= $count) {
                if ($lineNum > 0) { // Skip header
                    $rows[] = $row;
                }
                $lineNum++;
            }
            fclose($handle);

            return $rows;
        }

        // For Excel files, use PhpSpreadsheet with limited row reading
        try {
            $reader = IOFactory::createReaderForFile($file->getPathname());
            $reader->setReadDataOnly(true);

            // Read rows 2 through count+1 (skip header row 1)
            $reader->setReadFilter(new LimitedRowReadFilter(2, $count + 1));

            $spreadsheet = $reader->load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();

            $rows = [];
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            for ($row = 2; $row <= $count + 1; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getValue();
                    $rowData[] = $cellValue;
                }
                // Only add if row has some data
                if (array_filter($rowData, fn ($v) => $v !== null && $v !== '')) {
                    $rows[] = $rowData;
                }
            }

            // Clean up memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Count rows in a file efficiently without loading all data.
     */
    public function countRows(UploadedFile $file): int
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // For CSV, count lines efficiently
        if ($extension === 'csv') {
            $lineCount = 0;
            $handle = fopen($file->getPathname(), 'r');
            if ($handle) {
                while (fgets($handle) !== false) {
                    $lineCount++;
                }
                fclose($handle);
            }

            return max(0, $lineCount - 1); // Subtract header row
        }

        // For Excel files, use PhpSpreadsheet to get row count without loading all data
        try {
            $reader = IOFactory::createReaderForFile($file->getPathname());
            $reader->setReadDataOnly(true);

            // Load only the worksheet info (not all data)
            $worksheetInfo = $reader->listWorksheetInfo($file->getPathname());

            if (! empty($worksheetInfo[0]['totalRows'])) {
                return max(0, $worksheetInfo[0]['totalRows'] - 1); // Subtract header
            }
        } catch (\Exception $e) {
            // Fallback: load and count (memory intensive)
        }

        // Fallback for other formats
        $data = Excel::toArray([], $file);

        if (empty($data) || empty($data[0])) {
            return 0;
        }

        return max(0, count($data[0]) - 1);
    }

    /**
     * Create addresses from file using field mappings.
     *
     * @param  array<int, array<string, string>>  $mappings
     * @return Collection<int, Address>
     */
    public function createAddressesFromFile(
        UploadedFile $file,
        ImportBatch $batch,
        array $mappings
    ): Collection {
        $headers = $this->parseHeaders($file);
        $rows = $this->parseRows($file);
        $addresses = collect();

        // Build header index map
        $headerIndex = [];
        foreach ($headers as $index => $header) {
            $headerIndex[$header] = $index;
        }

        // Resolve pass-through fields to actual extra_N fields
        $resolvedMappings = $this->resolvePassThroughFields($mappings);

        // Update the batch with resolved mappings for export compatibility
        $batch->update(['field_mappings' => $resolvedMappings]);

        // Build mapping from position to target field
        $positionToField = [];
        foreach ($resolvedMappings as $mapping) {
            $sourceHeader = $mapping['source'] ?? null;
            $targetField = $mapping['target'] ?? null;

            if (! $sourceHeader || ! isset($headerIndex[$sourceHeader])) {
                continue;
            }

            // Skip fields marked as _skip
            if ($targetField === '_skip' || empty($targetField)) {
                continue;
            }

            $positionToField[$headerIndex[$sourceHeader]] = $targetField;
        }

        foreach ($rows as $rowIndex => $row) {
            // Calculate actual file row number (1-based, header is row 1, so data starts at row 2)
            $fileRowNumber = $rowIndex + 2;

            $addressData = [
                'import_batch_id' => $batch->id,
                'source' => 'import',
                'source_row_number' => $fileRowNumber,
                'created_by' => auth()->id(),
            ];

            // Separate extra fields (stored in JSON) from regular fields
            $extraData = [];

            foreach ($positionToField as $position => $field) {
                $value = $row[$position] ?? null;

                if ($value !== null && $value !== '') {
                    // Map legacy field names to new schema
                    $mappedField = $this->mapToNewFieldName($field);

                    // Extra fields go into JSON
                    if (str_starts_with($field, 'extra_')) {
                        $extraData[$field] = $value;
                    } else {
                        $addressData[$mappedField] = $value;
                    }
                }
            }

            // Store extra data as JSON
            if (! empty($extraData)) {
                $addressData['extra_data'] = $extraData;
            }

            // Parse input_address_1 to extract suite/unit info into input_address_2
            if (! empty($addressData['input_address_1'])) {
                $addressData = $this->parseAddressLine($addressData);
            }

            // Look up ship_via_code_id if ship_via_code is provided
            if (! empty($addressData['ship_via_code'])) {
                $shipViaCode = ShipViaCode::lookup($addressData['ship_via_code']);
                if ($shipViaCode) {
                    $addressData['ship_via_code_id'] = $shipViaCode->id;
                }
            }

            // Only create if we have at least input_address_1
            if (! empty($addressData['input_address_1'])) {
                $addresses->push(Address::create($addressData));
            }
        }

        return $addresses;
    }

    /**
     * Resolve pass-through fields to actual extra_N fields.
     * Skips over any extra_N fields that are already explicitly mapped.
     *
     * @param  array<int, array<string, string>>  $mappings
     * @return array<int, array<string, string>>
     */
    public function resolvePassThroughFields(array $mappings): array
    {
        // First pass: collect all explicitly mapped extra_N fields
        $usedExtraFields = [];
        foreach ($mappings as $mapping) {
            $targetField = $mapping['target'] ?? null;
            if ($targetField && str_starts_with($targetField, 'extra_')) {
                $usedExtraFields[] = $targetField;
            }
        }

        // Second pass: resolve _passthrough to next available extra_N
        $resolvedMappings = [];
        $nextExtraIndex = 1;

        foreach ($mappings as $mapping) {
            $targetField = $mapping['target'] ?? null;

            if ($targetField === '_passthrough') {
                // Find next available extra field
                while ($nextExtraIndex <= 20 && in_array("extra_{$nextExtraIndex}", $usedExtraFields, true)) {
                    $nextExtraIndex++;
                }

                if ($nextExtraIndex <= 20) {
                    $mapping['target'] = "extra_{$nextExtraIndex}";
                    $usedExtraFields[] = "extra_{$nextExtraIndex}";
                    $nextExtraIndex++;
                } else {
                    // All extra fields used, mark as skip
                    $mapping['target'] = '_skip';
                }
            }

            $resolvedMappings[] = $mapping;
        }

        return $resolvedMappings;
    }

    /**
     * Map legacy field names to new schema field names.
     */
    protected function mapToNewFieldName(string $field): string
    {
        return match ($field) {
            'name' => 'input_name',
            'company' => 'input_company',
            'address_line_1' => 'input_address_1',
            'address_line_2' => 'input_address_2',
            'city' => 'input_city',
            'state' => 'input_state',
            'postal_code' => 'input_postal',
            'country_code' => 'input_country',
            default => $field,
        };
    }

    /**
     * Parse input_address_1 to extract suite/unit/apt info into input_address_2.
     *
     * Handles patterns like:
     * - "Exchange Building #341" -> addr1: "Exchange Building", addr2: "STE 341"
     * - "8615 Tidwell Rd, Ste B" -> addr1: "8615 Tidwell Rd", addr2: "STE B"
     * - "5095 Blue Diamond Rd Ste A-7" -> addr1: "5095 Blue Diamond Rd", addr2: "STE A-7"
     * - "10722 BEVERLY BLVD STE B & C" -> addr1: "10722 BEVERLY BLVD", addr2: "STE B & C"
     *
     * If input_address_2 already has content, the extracted unit is appended with a comma.
     *
     * @param  array<string, mixed>  $addressData
     * @return array<string, mixed>
     */
    public function parseAddressLine(array $addressData): array
    {
        $addressLine1 = $addressData['input_address_1'] ?? '';

        // Skip if input_address_1 is empty
        if (empty($addressLine1)) {
            return $addressData;
        }

        $extracted = $this->extractSecondaryUnit($addressLine1);

        if ($extracted) {
            $addressData['input_address_1'] = $extracted['address'];

            // If input_address_2 already has content, append the extracted unit
            if (! empty($addressData['input_address_2'])) {
                $addressData['input_address_2'] = trim($addressData['input_address_2']).', '.$extracted['unit'];
            } else {
                $addressData['input_address_2'] = $extracted['unit'];
            }
        }

        return $addressData;
    }

    /**
     * Extract secondary unit designation from address string.
     *
     * Only extracts when standard unit abbreviations are found:
     * STE, SUITE, APT, APARTMENT, UNIT, BLDG, BUILDING, FL, FLOOR, RM, ROOM
     *
     * Does NOT extract bare # symbols - those are often route numbers or other identifiers.
     *
     * @return array{address: string, unit: string}|null
     */
    protected function extractSecondaryUnit(string $address): ?array
    {
        $address = trim($address);

        // Standard unit keywords that indicate a secondary address
        $unitKeywords = 'ste|suite|unit|apt|apartment|bldg|building|fl|floor|rm|room';

        // Pattern 1: Comma followed by unit designation (e.g., "123 Main St, Ste B", "456 Oak Ave, Unit 7")
        if (preg_match('/^(.+?),\s*((?:'.$unitKeywords.')\s*[A-Za-z0-9][\w\-&\s]*)$/i', $address, $matches)) {
            $mainAddress = trim($matches[1]);
            $unitPart = trim($matches[2]);
            $unit = $this->normalizeUnitDesignation($unitPart);

            if (strlen($mainAddress) >= 5) {
                return ['address' => $mainAddress, 'unit' => $unit];
            }
        }

        // Pattern 2: Space followed by unit keywords (e.g., "123 Main St Ste B", "456 Oak STE A-7")
        // Match the pattern: address + space + keyword + space + unit identifier
        if (preg_match('/^(.+?)\s+('.$unitKeywords.')\s+([A-Za-z0-9][\w\-&\s]*)$/i', $address, $matches)) {
            $mainAddress = trim($matches[1]);
            $keyword = strtoupper(trim($matches[2]));
            $unitNumber = trim($matches[3]);

            // Normalize the keyword
            $normalizedKeyword = $this->normalizeUnitKeyword($keyword);
            $unit = $normalizedKeyword.' '.$unitNumber;

            // Ensure main address still looks valid
            if (strlen($mainAddress) >= 5 && $this->looksLikeValidAddress($mainAddress)) {
                return ['address' => $mainAddress, 'unit' => $unit];
            }
        }

        // Pattern 3: Hash with preceding unit keyword (e.g., "123 Main St Apt #5", "456 Oak Suite #A")
        // Only extract # when it follows a unit keyword
        if (preg_match('/^(.+?)\s+('.$unitKeywords.')\s*#\s*([A-Za-z0-9][\w\-&\s]*)$/i', $address, $matches)) {
            $mainAddress = trim($matches[1]);
            $keyword = strtoupper(trim($matches[2]));
            $unitNumber = trim($matches[3]);

            $normalizedKeyword = $this->normalizeUnitKeyword($keyword);
            $unit = $normalizedKeyword.' '.$unitNumber;

            if (strlen($mainAddress) >= 5 && $this->looksLikeValidAddress($mainAddress)) {
                return ['address' => $mainAddress, 'unit' => $unit];
            }
        }

        return null;
    }

    /**
     * Normalize unit designation to standard format.
     */
    protected function normalizeUnitDesignation(string $unitPart): string
    {
        $unitPart = trim($unitPart);

        // Remove leading # if present
        if (str_starts_with($unitPart, '#')) {
            $unitPart = 'STE '.trim(substr($unitPart, 1));

            return $unitPart;
        }

        // Extract keyword and number
        if (preg_match('/^(ste|suite|unit|apt|apartment|bldg|building|fl|floor|rm|room)\s*(.*)$/i', $unitPart, $matches)) {
            $keyword = $this->normalizeUnitKeyword($matches[1]);
            $number = trim($matches[2]);

            return $keyword.' '.$number;
        }

        return $unitPart;
    }

    /**
     * Normalize unit keyword to standard abbreviation.
     */
    protected function normalizeUnitKeyword(string $keyword): string
    {
        $keyword = strtoupper(trim($keyword));

        return match ($keyword) {
            'SUITE', 'STE' => 'STE',
            'APARTMENT', 'APT' => 'APT',
            'UNIT' => 'UNIT',
            'BUILDING', 'BLDG' => 'BLDG',
            'FLOOR', 'FL' => 'FL',
            'ROOM', 'RM' => 'RM',
            default => $keyword,
        };
    }

    /**
     * Check if a string looks like a valid street address (basic heuristic).
     */
    protected function looksLikeValidAddress(string $address): bool
    {
        // Should contain at least one number (street number) and some letters
        $hasNumber = preg_match('/\d/', $address);
        $hasLetters = preg_match('/[a-zA-Z]{2,}/', $address);

        // Common street suffixes
        $streetSuffixes = 'st|street|ave|avenue|blvd|boulevard|rd|road|dr|drive|ln|lane|ct|court|pl|place|way|pkwy|parkway|cir|circle|hwy|highway|trl|trail';
        $hasStreetSuffix = preg_match('/\b('.$streetSuffixes.')\b/i', $address);

        // Either has both number and letters, or has a street suffix
        return ($hasNumber && $hasLetters) || $hasStreetSuffix;
    }

    /**
     * Get system fields available for mapping.
     *
     * @return array<string, string>
     */
    public function getSystemFields(): array
    {
        // Start with special options
        $fields = [
            '_passthrough' => '📦 Pass-Through (Auto Extra Field)',
            '_skip' => '⏭️ Skip (Do Not Import)',
        ];

        // Add address system fields
        $fields = array_merge($fields, Address::getSystemFields());

        // Add extra fields for explicit mapping (dynamic count from settings)
        $extraFieldCount = CompanySetting::instance()->getExtraFieldCount();
        for ($i = 1; $i <= $extraFieldCount; $i++) {
            $fields["extra_{$i}"] = "Extra Field {$i}";
        }

        return $fields;
    }

    /**
     * Auto-match headers to system fields using intelligent pattern matching.
     *
     * @param  array<int, string>  $headers
     * @return array<int, array<string, string>>
     */
    public function autoMatchHeaders(array $headers): array
    {
        $mappings = [];
        $usedFields = []; // Track already-matched fields to prevent duplicates

        foreach ($headers as $position => $header) {
            $normalizedHeader = $this->normalizeHeader($header);
            $matchedField = $this->findBestMatch($normalizedHeader, $usedFields);

            if ($matchedField) {
                $usedFields[] = $matchedField;
            }

            $mappings[] = [
                'position' => $position,
                'source' => $header,
                'target' => $matchedField ?? '_passthrough', // Default to pass-through
            ];
        }

        return $mappings;
    }

    /**
     * Normalize a header for matching.
     * Converts to lowercase, removes common prefixes, and standardizes format.
     */
    protected function normalizeHeader(string $header): string
    {
        $normalized = trim($header);

        // Convert camelCase/PascalCase to spaces BEFORE lowercasing (e.g., "ShipToPhoneNo" -> "Ship To Phone No")
        $normalized = preg_replace('/([a-z])([A-Z])/', '$1 $2', $normalized);

        // Now convert to lowercase
        $normalized = strtolower($normalized);

        // Replace underscores and hyphens with spaces
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        // Remove common prefixes that don't affect meaning
        // But preserve "ship to name" / "ship to contact" as a unit for proper matching
        $prefixes = [
            'destination ',
            'dest ',
            'shipping ',
            'delivery ',
            'deliver to ',
            'deliver ',
            'billing ',
            'bill to ',
            'billto ',
            'bill ',
            'rcpt ',
            'consignee ',
            'customer ',
            'cust ',
            'primary ',
            'main ',
        ];

        // Only strip these prefixes if NOT followed by "name" or "contact" (preserve "ship to name", "ship to contact", etc.)
        $conditionalPrefixes = [
            'ship to ' => ['name', 'contact'],
            'shipto ' => ['name', 'contact'],
            'shipto' => ['name', 'contact'],  // No space - handles "ShipToContact"
            'ship ' => ['name', 'contact'],
            'recipient ' => ['name', 'contact'],
            'to ' => ['name', 'contact'],
            'delivery ' => ['name', 'contact'],
            'deliver to ' => ['name', 'contact'],
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }

        // Handle conditional prefixes - only strip if not followed by preserved words
        foreach ($conditionalPrefixes as $prefix => $preserveIfFollowedBy) {
            if (str_starts_with($normalized, $prefix)) {
                $remainder = substr($normalized, strlen($prefix));
                $shouldPreserve = false;
                foreach ($preserveIfFollowedBy as $word) {
                    if (str_starts_with($remainder, $word)) {
                        $shouldPreserve = true;
                        break;
                    }
                }
                if (! $shouldPreserve) {
                    $normalized = $remainder;
                }
                break;
            }
        }

        // Remove common suffixes
        $suffixes = [' field', ' info', ' data'];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                $normalized = substr($normalized, 0, -strlen($suffix));
            }
        }

        // Remove extra spaces
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        return $normalized;
    }

    /**
     * Find the best matching system field for a normalized header.
     *
     * @param  array<int, string>  $usedFields
     */
    protected function findBestMatch(string $normalizedHeader, array $usedFields): ?string
    {
        // Define patterns for each system field (in order of priority)
        // Field names match Address::getSystemFields()
        $patterns = [
            'input_address_1' => [
                'exact' => ['address', 'address 1', 'address1', 'addr', 'addr 1', 'addr1', 'add1', 'add 1', 'street', 'street 1', 'street1', 'str1', 'street address', 'address line 1', 'addressline1', 'line 1', 'line1'],
                'contains' => ['address1', 'addr1', 'add1', 'street1', 'line1'],
                'regex' => ['/^addr(?:ess)?[\s_-]*1?$/i', '/^street[\s_-]*(?:address)?[\s_-]*1?$/i'],
            ],
            'input_address_2' => [
                'exact' => ['address 2', 'address2', 'addr 2', 'addr2', 'add2', 'add 2', 'street 2', 'street2', 'str2', 'apt', 'apartment', 'suite', 'ste', 'unit', 'floor', 'building', 'bldg', 'address line 2', 'addressline2', 'line 2', 'line2'],
                'contains' => ['address2', 'addr2', 'add2', 'street2', 'line2'],
                'regex' => ['/^addr(?:ess)?[\s_-]*2$/i'],
            ],
            'input_city' => [
                'exact' => ['city', 'town', 'municipality', 'locality', 'suburb'],
                'contains' => ['city'],
                'regex' => [],
            ],
            'input_state' => [
                'exact' => ['state', 'st', 'province', 'prov', 'region', 'state province', 'state prov', 'territory'],
                'contains' => ['state', 'province', 'prov'],
                'regex' => [],
            ],
            'input_postal' => [
                'exact' => ['zip', 'zip code', 'zipcode', 'zip5', 'postal', 'postal code', 'postalcode', 'postcode', 'post code', 'pc'],
                'contains' => ['zip', 'postal', 'postcode'],
                'regex' => ['/^zip[\s_-]*(?:code)?[\s_-]*\d*$/i', '/^postal[\s_-]*(?:code)?$/i'],
            ],
            'input_country' => [
                'exact' => ['country', 'country code', 'countrycode', 'nation', 'cc'],
                'contains' => ['country'],
                'regex' => [],
            ],
            'input_name' => [
                // Person/contact name - NOT the company/ship-to name
                'exact' => ['contact', 'contact name', 'contactname', 'attention', 'attn', 'attn name', 'care of', 'c/o', 'addressee', 'person', 'person name', 'full name', 'fullname', 'recipient contact', 'ship to contact', 'shipto contact', 'shiptocontact', 'delivery contact'],
                'contains' => ['contact'],  // Any field with "contact" = person's name
                'regex' => ['/^(?:attn|attention|c\/?o|care of)[\s_:-]*/i'],
            ],
            'input_company' => [
                // Company/business name - this is typically the "Ship To Name" or primary recipient
                'exact' => ['company', 'company name', 'companyname', 'business', 'business name', 'businessname', 'organization', 'org', 'firm', 'corp', 'corporation', 'enterprise', 'name', 'recipient', 'recipient name', 'ship to name', 'shipto name', 'shiptoname', 'consignee name', 'deliver to name', 'delivery name'],
                'contains' => ['company', 'business', 'organization'],
                'regex' => [],
            ],
            'external_reference' => [
                'exact' => ['reference', 'ref', 'reference id', 'refid', 'ref id', 'external reference', 'external ref', 'order', 'order id', 'orderid', 'order number', 'ordernumber', 'order num', 'po', 'po number', 'ponumber', 'invoice', 'invoice id', 'job', 'job number', 'jobnumber', 'ticket', 'ticket number', 'id', 'record id', 'recordid'],
                'contains' => ['reference', 'order', 'invoice', 'ticket'],
                'regex' => ['/^(?:order|ref|po|job|ticket)[\s_-]*(?:id|num(?:ber)?)?$/i'],
            ],
        ];

        // Note: 'phone' and 'email' patterns removed - these are not valid Address model fields
        // If phone/email fields are added to the Address model in the future, add patterns here

        // Try exact matches first (highest priority)
        foreach ($patterns as $field => $matchTypes) {
            if (in_array($field, $usedFields, true)) {
                continue;
            }

            if (in_array($normalizedHeader, $matchTypes['exact'], true)) {
                return $field;
            }
        }

        // Try contains matches (medium priority)
        foreach ($patterns as $field => $matchTypes) {
            if (in_array($field, $usedFields, true)) {
                continue;
            }

            foreach ($matchTypes['contains'] as $substring) {
                if (str_contains($normalizedHeader, $substring)) {
                    return $field;
                }
            }
        }

        // Try regex matches (lower priority)
        foreach ($patterns as $field => $matchTypes) {
            if (in_array($field, $usedFields, true)) {
                continue;
            }

            foreach ($matchTypes['regex'] as $regex) {
                if (preg_match($regex, $normalizedHeader)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Save mappings as a template.
     *
     * @param  array<int, array<string, string>>  $mappings
     */
    public function saveMappingTemplate(
        string $name,
        array $mappings,
        ?string $description = null,
        ?string $shipViaCodeField = null
    ): ImportFieldTemplate {
        return ImportFieldTemplate::create([
            'name' => $name,
            'description' => $description,
            'field_mappings' => $mappings,
            'ship_via_code_field' => $shipViaCodeField,
            'is_default' => false,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Update an existing mapping template.
     *
     * @param  array<int, array<string, string>>  $mappings
     */
    public function updateMappingTemplate(
        ImportFieldTemplate $template,
        array $mappings,
        ?string $shipViaCodeField = null,
        ?string $description = null
    ): ImportFieldTemplate {
        $data = [
            'field_mappings' => $mappings,
            'ship_via_code_field' => $shipViaCodeField,
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        $template->update($data);

        return $template->fresh();
    }
}

/**
 * Read filter to limit rows loaded into memory.
 */
class LimitedRowReadFilter implements IReadFilter
{
    public function __construct(
        protected int $startRow,
        protected int $endRow
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
