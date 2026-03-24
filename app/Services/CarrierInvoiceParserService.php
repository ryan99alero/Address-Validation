<?php

namespace App\Services;

use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use Illuminate\Support\Facades\Log;

class CarrierInvoiceParserService
{
    public function __construct(
        protected ?ShippingDatabaseService $shippingDb = null
    ) {
        $this->shippingDb = $shippingDb ?? app(ShippingDatabaseService::class);
    }

    /**
     * Parse a carrier invoice file and store the corrections.
     *
     * @return array{total_records: int, corrections: int, new_corrections: int, duplicates: int, total_charges: float}
     */
    public function parse(CarrierInvoice $invoice, string $filePath): array
    {
        $invoice->markProcessing();

        try {
            $carrier = $invoice->carrier;

            // Route to carrier-specific parser
            $result = match (strtolower($carrier->slug)) {
                'ups' => $this->parseUpsInvoice($invoice, $filePath),
                'fedex' => $this->parseFedExInvoice($invoice, $filePath),
                default => throw new \Exception("Unknown carrier: {$carrier->slug}"),
            };

            // For FedEx, try to backfill missing original addresses from shipping DB
            if (strtolower($carrier->slug) === 'fedex') {
                $this->backfillFedExOriginalAddresses($invoice);
            }

            // Link all correction lines to the address cache
            $newCorrections = $this->linkCorrectionsToCache($invoice);
            $result['new_corrections'] = $newCorrections;
            $result['duplicates'] = $result['corrections'] - $newCorrections;

            $invoice->markCompleted(
                $result['total_records'],
                $result['corrections'],
                $result['new_corrections'],
                $result['duplicates'],
                $result['total_charges']
            );

            return $result;

        } catch (\Exception $e) {
            $invoice->markFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Backfill missing original addresses for FedEx invoice lines from shipping database.
     * Processes in batches of 100 to avoid overloading the shipping DB.
     * Tracks lookup status to prevent redundant lookups.
     */
    protected function backfillFedExOriginalAddresses(CarrierInvoice $invoice): void
    {
        // Check if shipping DB is available
        if (! $this->shippingDb->isAvailable()) {
            Log::info('Shipping DB not configured, skipping FedEx original address backfill');

            return;
        }

        // Get lines needing lookup (null original_address_1 AND null shipping_lookup_status)
        $totalNeedingLookup = $invoice->correctionLines()->needsShippingLookup()->count();

        if ($totalNeedingLookup === 0) {
            return;
        }

        Log::info('Backfilling FedEx original addresses', [
            'invoice_id' => $invoice->id,
            'lines_count' => $totalNeedingLookup,
        ]);

        $totalFound = 0;
        $totalNotFound = 0;
        $batchSize = 100;

        // Process in batches of 100
        $invoice->correctionLines()
            ->needsShippingLookup()
            ->chunk($batchSize, function ($lines) use (&$totalFound, &$totalNotFound) {
                // Collect tracking numbers for this batch
                $trackingNumbers = $lines->pluck('tracking_number')->filter()->toArray();

                if (empty($trackingNumbers)) {
                    return;
                }

                // Batch lookup - returns array keyed by tracking number
                $shipments = $this->shippingDb->lookupBatch($trackingNumbers);

                foreach ($lines as $line) {
                    $trackingNumber = $line->tracking_number;

                    if (isset($shipments[$trackingNumber]) && ! empty($shipments[$trackingNumber]['add1'])) {
                        $shipment = $shipments[$trackingNumber];

                        $line->update([
                            'original_name' => $shipment['contact'] ?: $line->original_name,
                            'original_company' => $shipment['company'] ?: $line->original_company,
                            'original_address_1' => $shipment['add1'],
                            'original_address_2' => $shipment['add2'],
                            'original_city' => $shipment['city'],
                            'original_state' => $shipment['state'],
                            'original_postal' => $shipment['zipcode'],
                            'original_country' => $shipment['country'] ?: 'US',
                            'shipping_lookup_status' => CarrierInvoiceLine::LOOKUP_STATUS_FOUND,
                            'shipping_lookup_at' => now(),
                        ]);
                        $totalFound++;
                    } else {
                        // Mark as not found but keep the line for potential future lookup
                        // The line still has value - it records the correction charge and corrected address
                        $line->update([
                            'shipping_lookup_status' => CarrierInvoiceLine::LOOKUP_STATUS_NOT_FOUND,
                            'shipping_lookup_at' => now(),
                        ]);
                        $totalNotFound++;
                    }
                }
            });

        Log::info('FedEx original address backfill complete', [
            'invoice_id' => $invoice->id,
            'found' => $totalFound,
            'not_found' => $totalNotFound,
        ]);
    }

    /**
     * Parse UPS invoice file (no header row).
     *
     * UPS Billing Data format (actual column positions from sample):
     * - Column 13: Tracking Number (1Z...)
     * - Column 11: Ship Date
     * - Column 35: Charge Code (ADC = Address Correction)
     * - Column 52: Charge Amount (gross)
     * - Columns 66-73: Original address (Name, Company, Addr1, empty, City, State, Zip, Country)
     * - Columns 75-81: Corrected address (Company, Addr1, empty, City, State, Zip, Country)
     */
    protected function parseUpsInvoice(CarrierInvoice $invoice, string $filePath): array
    {
        Log::info('Parsing UPS invoice', ['file' => $filePath]);

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \Exception("Cannot open file: {$filePath}");
        }

        $totalRecords = 0;
        $corrections = 0;
        $totalCharges = 0.0;
        $seenTrackingNumbers = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $totalRecords++;

            // Check if this is an address correction line (charge code ADC)
            $chargeCode = trim($row[35] ?? '');
            if ($chargeCode !== 'ADC') {
                continue;
            }

            $trackingNumber = trim($row[13] ?? '');
            if (empty($trackingNumber)) {
                continue;
            }

            // Skip if we've already processed this tracking number in this file
            // (multiple lines for same shipment)
            if (isset($seenTrackingNumbers[$trackingNumber])) {
                // Add to existing charge amount
                $chargeAmount = $this->parseAmount($row[52] ?? '0');
                $totalCharges += $chargeAmount;

                continue;
            }
            $seenTrackingNumbers[$trackingNumber] = true;

            // Parse dates
            $shipDate = $this->parseDate($row[11] ?? '');

            // Parse charge amount (column 52 is gross charge)
            $chargeAmount = $this->parseAmount($row[52] ?? '0');
            $totalCharges += $chargeAmount;

            // Parse original address (columns 66-73)
            $originalName = trim($row[66] ?? '');
            $originalCompany = trim($row[67] ?? '');
            $originalAddress1 = trim($row[68] ?? '');
            $originalAddress2 = trim($row[69] ?? '');
            $originalCity = trim($row[70] ?? '');
            $originalState = trim($row[71] ?? '');
            $originalPostal = trim($row[72] ?? '');
            $originalCountry = trim($row[73] ?? 'US');

            // Parse corrected address (columns 75-81)
            $correctedCompany = trim($row[75] ?? '');
            $correctedAddress1 = trim($row[76] ?? '');
            $correctedAddress2 = trim($row[77] ?? '');
            $correctedCity = trim($row[78] ?? '');
            $correctedState = trim($row[79] ?? '');
            $correctedPostal = trim($row[80] ?? '');
            $correctedCountry = trim($row[81] ?? 'US');

            // Only create line if we have address data
            if (empty($originalAddress1) && empty($correctedAddress1)) {
                continue;
            }

            $this->createInvoiceLine($invoice, [
                'tracking_number' => $trackingNumber,
                'ship_date' => $shipDate,
                'original_name' => $originalName,
                'original_company' => $originalCompany,
                'original_address_1' => $originalAddress1,
                'original_address_2' => $originalAddress2,
                'original_city' => $originalCity,
                'original_state' => $originalState,
                'original_postal' => $originalPostal,
                'original_country' => $originalCountry ?: 'US',
                'corrected_address_1' => $correctedAddress1,
                'corrected_address_2' => $correctedAddress2,
                'corrected_city' => $correctedCity,
                'corrected_state' => $correctedState,
                'corrected_postal' => $correctedPostal,
                'corrected_country' => $correctedCountry ?: 'US',
                'charge_code' => 'ADC',
                'charge_description' => 'Address Correction',
                'charge_amount' => $chargeAmount,
            ]);

            $corrections++;
        }

        fclose($handle);

        return [
            'total_records' => $totalRecords,
            'corrections' => $corrections,
            'new_corrections' => 0, // Will be calculated after cache linking
            'duplicates' => 0,
            'total_charges' => $totalCharges,
        ];
    }

    /**
     * Parse FedEx invoice file (has header row).
     *
     * FedEx CSV format (0-based column indices):
     * - Row 1: Header with column names
     * - Column 9: Express or Ground Tracking ID
     * - Column 14: Shipment Date
     * - Column 15: POD Delivery Date
     * - Columns 33-40: Recipient (corrected) address
     * - Columns 58-63: Original Recipient address (often empty)
     * - Column 96: Ground Tracking ID Address Correction Gross Charge Amount
     * - Charge descriptions/amounts in pairs starting at column 107
     */
    protected function parseFedExInvoice(CarrierInvoice $invoice, string $filePath): array
    {
        Log::info('Parsing FedEx invoice', ['file' => $filePath]);

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \Exception("Cannot open file: {$filePath}");
        }

        // Read header row to map column names to indices
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header) {
            throw new \Exception('Empty file or invalid CSV format');
        }

        $columnMap = array_flip(array_map('trim', $header));

        $totalRecords = 0;
        $corrections = 0;
        $totalCharges = 0.0;
        $seenTrackingNumbers = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $totalRecords++;

            // Check for address correction charge
            $correctionCharge = $this->findFedExAddressCorrectionCharge($row, $columnMap);
            if ($correctionCharge <= 0) {
                continue;
            }

            $trackingNumber = $this->parseTrackingNumber($row[$columnMap['Express or Ground Tracking ID'] ?? 9] ?? '');
            if (empty($trackingNumber)) {
                continue;
            }

            // Skip duplicates within same file
            if (isset($seenTrackingNumbers[$trackingNumber])) {
                $totalCharges += $correctionCharge;

                continue;
            }
            $seenTrackingNumbers[$trackingNumber] = true;

            $totalCharges += $correctionCharge;

            // Parse dates
            $shipDate = $this->parseDate($row[$columnMap['Shipment Date'] ?? 14] ?? '');
            $deliveryDate = $this->parseDate($row[$columnMap['POD Delivery Date'] ?? 15] ?? '');

            // Parse corrected address (Recipient columns)
            $correctedName = trim($row[$columnMap['Recipient Name'] ?? 33] ?? '');
            $correctedCompany = trim($row[$columnMap['Recipient Company'] ?? 34] ?? '');
            $correctedAddress1 = trim($row[$columnMap['Recipient Address Line 1'] ?? 35] ?? '');
            $correctedAddress2 = trim($row[$columnMap['Recipient Address Line 2'] ?? 36] ?? '');
            $correctedCity = trim($row[$columnMap['Recipient City'] ?? 37] ?? '');
            $correctedState = trim($row[$columnMap['Recipient State'] ?? 38] ?? '');
            $correctedPostal = trim($row[$columnMap['Recipient Zip Code'] ?? 39] ?? '');
            $correctedCountry = trim($row[$columnMap['Recipient Country/Territory'] ?? 40] ?? 'US');

            // Parse original address (often empty in FedEx invoices)
            $originalAddress1 = trim($row[$columnMap['Original Recipient Address Line 1'] ?? 58] ?? '');
            $originalAddress2 = trim($row[$columnMap['Original Recipient Address Line 2'] ?? 59] ?? '');
            $originalCity = trim($row[$columnMap['Original Recipient City'] ?? 60] ?? '');
            $originalState = trim($row[$columnMap['Original Recipient State'] ?? 61] ?? '');
            $originalPostal = trim($row[$columnMap['Original Recipient Zip Code'] ?? 62] ?? '');
            $originalCountry = trim($row[$columnMap['Original Recipient Country/Territory'] ?? 63] ?? 'US');

            // Skip if no corrected address data
            if (empty($correctedAddress1)) {
                continue;
            }

            // Determine if we have a real original address (different from corrected)
            // FedEx often doesn't include original - we still record the charge but can't build cache mapping
            $hasOriginalAddress = ! empty($originalAddress1);

            $this->createInvoiceLine($invoice, [
                'tracking_number' => $trackingNumber,
                'ship_date' => $shipDate,
                'delivery_date' => $deliveryDate,
                'original_name' => $correctedName, // FedEx doesn't have separate original name
                'original_company' => $correctedCompany,
                // Only set original address if we actually have it (different from corrected)
                'original_address_1' => $hasOriginalAddress ? $originalAddress1 : null,
                'original_address_2' => $hasOriginalAddress ? $originalAddress2 : null,
                'original_city' => $hasOriginalAddress ? $originalCity : null,
                'original_state' => $hasOriginalAddress ? $originalState : null,
                'original_postal' => $hasOriginalAddress ? $originalPostal : null,
                'original_country' => $hasOriginalAddress ? ($originalCountry ?: 'US') : null,
                'corrected_address_1' => $correctedAddress1,
                'corrected_address_2' => $correctedAddress2,
                'corrected_city' => $correctedCity,
                'corrected_state' => $correctedState,
                'corrected_postal' => $correctedPostal,
                'corrected_country' => $correctedCountry ?: 'US',
                'charge_code' => 'ADDCOR',
                'charge_description' => 'Address Correction',
                'charge_amount' => $correctionCharge,
            ]);

            $corrections++;
        }

        fclose($handle);

        return [
            'total_records' => $totalRecords,
            'corrections' => $corrections,
            'new_corrections' => 0,
            'duplicates' => 0,
            'total_charges' => $totalCharges,
        ];
    }

    /**
     * Find address correction charge amount from FedEx row.
     * Checks dedicated columns and charge description/amount pairs.
     */
    protected function findFedExAddressCorrectionCharge(array $row, array $columnMap): float
    {
        // First check dedicated Address Correction Gross Charge column
        $grossIdx = $columnMap['Ground Tracking ID Address Correction Gross Charge Amount'] ?? null;
        if ($grossIdx !== null && isset($row[$grossIdx])) {
            $amount = $this->parseAmount($row[$grossIdx]);
            if ($amount > 0) {
                return $amount;
            }
        }

        // Search through charge description/amount pairs
        foreach ($row as $idx => $value) {
            if (stripos($value, 'Address Correction') !== false) {
                // Next column should be the amount
                $amountIdx = $idx + 1;
                if (isset($row[$amountIdx])) {
                    return $this->parseAmount($row[$amountIdx]);
                }
            }
        }

        return 0.0;
    }

    /**
     * Link all correction lines in an invoice to the address correction cache.
     * Returns the count of NEW corrections added (not duplicates).
     */
    protected function linkCorrectionsToCache(CarrierInvoice $invoice): int
    {
        $correctionLines = $invoice->correctionLines()->get();
        $newCorrections = 0;

        foreach ($correctionLines as $line) {
            $isNew = $line->linkToCorrectionCache();
            if ($isNew) {
                $newCorrections++;
            }
        }

        return $newCorrections;
    }

    /**
     * Parse tracking number, handling scientific notation from Excel exports.
     * Returns empty string for invalid tracking numbers (scientific notation loses precision).
     */
    protected function parseTrackingNumber(?string $trackingStr): string
    {
        if (empty($trackingStr)) {
            return '';
        }

        $trackingStr = trim($trackingStr);

        // Scientific notation means Excel corrupted the number - precision is lost
        // These result in trailing zeros and are useless for lookups
        if (stripos($trackingStr, 'E+') !== false || stripos($trackingStr, 'E-') !== false) {
            Log::debug('Skipping tracking number in scientific notation (precision lost)', [
                'raw' => $trackingStr,
            ]);

            return '';
        }

        // Validate tracking number format
        if (! $this->isValidTrackingNumber($trackingStr)) {
            Log::debug('Skipping invalid tracking number', ['tracking' => $trackingStr]);

            return '';
        }

        return $trackingStr;
    }

    /**
     * Validate tracking number format.
     * FedEx: 12-22 digits
     * UPS: starts with 1Z, 18 chars
     */
    protected function isValidTrackingNumber(string $trackingNumber): bool
    {
        // UPS format: 1Z followed by 16 alphanumeric characters
        if (str_starts_with($trackingNumber, '1Z')) {
            return strlen($trackingNumber) === 18;
        }

        // FedEx format: 12-22 digits (no letters except for some door tag numbers)
        // Skip if it has 3+ trailing zeros (likely corrupted from scientific notation)
        if (preg_match('/000{3,}$/', $trackingNumber)) {
            return false;
        }

        // FedEx tracking should be 12-22 digits
        if (preg_match('/^\d{12,22}$/', $trackingNumber)) {
            return true;
        }

        // FedEx door tag format: DT followed by digits
        if (preg_match('/^DT\d{12,}$/', $trackingNumber)) {
            return true;
        }

        return false;
    }

    /**
     * Parse a date string to Y-m-d format or null.
     */
    protected function parseDate(?string $dateStr): ?string
    {
        if (empty($dateStr)) {
            return null;
        }

        $dateStr = trim($dateStr);

        // Try various formats
        $formats = ['Y-m-d', 'Ymd', 'm/d/Y', 'm/d/y', 'Y/m/d'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($dateStr);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Parse an amount string to float.
     */
    protected function parseAmount(?string $amountStr): float
    {
        if (empty($amountStr)) {
            return 0.0;
        }

        // Remove currency symbols and whitespace
        $amountStr = preg_replace('/[^0-9.\-]/', '', trim($amountStr));

        return (float) $amountStr;
    }

    /**
     * Create an invoice line from parsed data.
     */
    protected function createInvoiceLine(CarrierInvoice $invoice, array $data): CarrierInvoiceLine
    {
        return CarrierInvoiceLine::create([
            'carrier_invoice_id' => $invoice->id,
            'tracking_number' => $data['tracking_number'] ?? null,
            'ship_date' => $data['ship_date'] ?? null,
            'delivery_date' => $data['delivery_date'] ?? null,
            'original_name' => $data['original_name'] ?? null,
            'original_company' => $data['original_company'] ?? null,
            'original_address_1' => $data['original_address_1'] ?? null,
            'original_address_2' => $data['original_address_2'] ?? null,
            'original_address_3' => $data['original_address_3'] ?? null,
            'original_city' => $data['original_city'] ?? null,
            'original_state' => $data['original_state'] ?? null,
            'original_postal' => $data['original_postal'] ?? null,
            'original_country' => $data['original_country'] ?? 'US',
            'corrected_address_1' => $data['corrected_address_1'] ?? null,
            'corrected_address_2' => $data['corrected_address_2'] ?? null,
            'corrected_address_3' => $data['corrected_address_3'] ?? null,
            'corrected_city' => $data['corrected_city'] ?? null,
            'corrected_state' => $data['corrected_state'] ?? null,
            'corrected_postal' => $data['corrected_postal'] ?? null,
            'corrected_country' => $data['corrected_country'] ?? 'US',
            'charge_code' => $data['charge_code'] ?? null,
            'charge_description' => $data['charge_description'] ?? null,
            'charge_amount' => $data['charge_amount'] ?? 0.0,
        ]);
    }
}
