<?php

namespace App\Services;

use App\Enums\ChargeDriver;
use App\Jobs\PushInvoiceChargebacks;
use App\Jobs\SyncInvoiceCartonCosts;
use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use App\Models\CarrierShipment;
use App\Services\Invoices\ChargeCategoryResolver;
use App\Services\Invoices\ChargeDriverResolver;
use App\Services\Invoices\FedExInvoiceParser;
use App\Services\Invoices\FedExShipmentDeriveService;
use App\Services\Invoices\InvoiceIdentity;
use App\Services\Invoices\PdfTextExtractor;
use App\Services\Invoices\UpsPdfChargeParser;
use App\Services\Invoices\UpsPdfInvoiceParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CarrierInvoiceParserService
{
    /**
     * The source format of the file currently being imported ('csv' | 'pdf'), stamped
     * onto each charge. Charge rows stay append-only + first-writer-wins on dedup;
     * source_type lets description precedence (CSV > PDF) be resolved at read time
     * instead of by mutating charges on write.
     */
    protected ?string $importSourceType = null;

    /**
     * Why the most recent importFile() produced no invoices (e.g. 'legacy_format'), for the
     * ingest layer to record on the import-file row. Null when a file imported normally.
     */
    public ?string $lastSkipReason = null;

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
            $isPdf = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf';

            // Route to carrier- and format-specific parser
            $result = match (strtolower($carrier->slug)) {
                'ups' => $isPdf
                    ? $this->parseUpsPdfInvoice($invoice, $filePath)
                    : $this->parseUpsInvoice($invoice, $filePath),
                'fedex' => $isPdf
                    ? $this->parseFedExPdfInvoice($invoice, $filePath)
                    : $this->parseFedExInvoice($invoice, $filePath),
                default => throw new \Exception("Unknown carrier: {$carrier->slug}"),
            };

            // For FedEx, try to backfill missing original addresses from shipping DB
            if (strtolower($carrier->slug) === 'fedex') {
                $this->backfillFedExOriginalAddresses($invoice);

                // FedEx has no printed per-shipment section, so derive per-shipment
                // rows from the charges just imported (total, service, billing type)
                // — the Per-Shipment Costs view is empty otherwise. Best-effort: a
                // derivation error must never fail the import.
                try {
                    app(FedExShipmentDeriveService::class)->deriveForInvoice($invoice);
                } catch (\Throwable $e) {
                    Log::warning('FedEx shipment derivation failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
                }
            }

            // UPS shipments come from the PDF; fill their base/fee cost split from the
            // charges so carrier_shipments carries the same per-shipment cost data.
            if (strtolower($carrier->slug) === 'ups') {
                try {
                    app(FedExShipmentDeriveService::class)->enrichCostsForInvoice($invoice);
                } catch (\Throwable $e) {
                    Log::warning('UPS shipment cost enrich failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
                }
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

            $chargeCode = trim($row[35] ?? '');

            // Capture EVERY charge line for fee analytics (one row = one charge).
            // UPS billing columns: 35=Charge Category Detail Code, 45=Charge
            // Description, 52=Net Amount, 33=Zone, 11=date, 13=tracking, 28=Billed Weight.
            $this->recordCharge($invoice, [
                'charge_code' => $chargeCode,
                'charge_description' => trim($row[45] ?? ''),
                'tracking_number' => trim($row[13] ?? ''),
                'amount' => $this->parseAmount($row[52] ?? '0'),
                'date' => $this->parseDate($row[11] ?? ''),
                'zone' => trim($row[33] ?? '') ?: null,
                'weight' => $this->parseWeight($row[28] ?? ''),
            ]);

            // Below: address-correction-specific handling for the cache.
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

        // The repeating "Tracking ID Charge Description / Amount" pairs start here.
        $pairStart = 107;
        foreach ($header as $idx => $name) {
            if (trim((string) $name) === 'Tracking ID Charge Description') {
                $pairStart = (int) $idx;
                break;
            }
        }

        $totalRecords = 0;
        $corrections = 0;
        $totalCharges = 0.0;
        $seenTrackingNumbers = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $totalRecords++;

            $trackingNumber = $this->parseTrackingNumber($row[$columnMap['Express or Ground Tracking ID'] ?? 9] ?? '');
            $shipDate = $this->parseDate($row[$columnMap['Shipment Date'] ?? 14] ?? '');

            // Capture ALL charges for analytics (every row): base transport + the
            // repeating Charge Description/Amount pairs (fuel, DAS, residential,
            // handling, address correction, discounts, etc.).
            $this->recordFedExCharges($invoice, $row, $pairStart, $trackingNumber, $shipDate);

            // --- Address-correction handling for the correction cache only ---
            $correctionCharge = $this->findFedExAddressCorrectionCharge($row, $columnMap);
            if ($correctionCharge <= 0) {
                continue;
            }
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
            // One malformed correction must never fail the whole file's import (a batch PDF holds
            // hundreds of invoices). Isolate each and keep going.
            try {
                $isNew = $line->linkToCorrectionCache();
            } catch (\Throwable $e) {
                Log::warning('Correction cache link failed', ['line_id' => $line->id, 'error' => $e->getMessage()]);

                continue;
            }
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
     * Parse a billed/rated weight value (e.g. "7.0", "7.0 lbs") to a positive
     * float, or null when absent/zero.
     */
    protected function parseWeight(?string $weightStr): ?float
    {
        if ($weightStr === null || trim($weightStr) === '') {
            return null;
        }

        $weight = (float) preg_replace('/[^0-9.]/', '', trim($weightStr));

        return $weight > 0 ? $weight : null;
    }

    /**
     * Parse a UPS invoice PDF for its Address Corrections.
     *
     * @return array{total_records: int, corrections: int, new_corrections: int, duplicates: int, total_charges: float}
     */
    protected function parseUpsPdfInvoice(CarrierInvoice $invoice, string $filePath): array
    {
        Log::info('Parsing UPS PDF invoice', ['file' => $filePath]);

        $text = (new PdfTextExtractor)->extractFile($filePath);
        $parsed = (new UpsPdfInvoiceParser)->parse($text);

        // Record invoice metadata when present.
        $meta = array_filter([
            'invoice_number' => $parsed['invoice_number'] ?? null,
            'account_number' => $parsed['account_number'] ?? null,
        ]);
        if (! empty($parsed['invoice_date'])) {
            try {
                $meta['invoice_date'] = Carbon::parse($parsed['invoice_date'])->toDateString();
            } catch (\Exception $e) {
                // Leave invoice_date unset if unparseable.
            }
        }
        if (! empty($meta)) {
            $invoice->update($meta);
        }

        $corrections = $this->buildCorrectionLines($invoice, $parsed['corrections']);

        return [
            'total_records' => count($parsed['corrections']),
            'corrections' => $corrections,
            'new_corrections' => 0, // computed later by linkCorrectionsToCache()
            'duplicates' => 0,
            'total_charges' => 0.0,
        ];
    }

    /**
     * Parse a FedEx invoice PDF (smalot-based, block state machine): records the
     * granular charge ledger per shipment AND the original->corrected address
     * pairs from the Ground Address Correction section.
     *
     * @return array{total_records: int, corrections: int, new_corrections: int, duplicates: int, total_charges: float}
     */
    protected function parseFedExPdfInvoice(CarrierInvoice $invoice, string $filePath): array
    {
        Log::info('Parsing FedEx PDF invoice', ['file' => $filePath]);

        $parsed = (new FedExInvoiceParser)->parse($filePath);

        $meta = array_filter([
            'invoice_number' => $parsed['meta']['invoice_number'] ?? null,
            'account_number' => $parsed['meta']['account_number'] ?? null,
        ]);
        if (! empty($parsed['meta']['invoice_date'])) {
            try {
                $meta['invoice_date'] = Carbon::parse($parsed['meta']['invoice_date'])->toDateString();
            } catch (\Exception $e) {
                // leave unset
            }
        }
        if (! empty($meta)) {
            $invoice->update($meta);
        }

        // Granular charges for fee analytics.
        foreach ($parsed['shipments'] as $shipment) {
            $date = null;
            if (! empty($shipment['ship_date'])) {
                try {
                    $date = Carbon::parse($shipment['ship_date'])->toDateString();
                } catch (\Exception $e) {
                    // leave null
                }
            }
            foreach ($shipment['charge_ledger'] as $charge) {
                $this->recordCharge($invoice, [
                    'charge_description' => $charge['description'],
                    'amount' => $charge['amount'],
                    'tracking_number' => $shipment['tracking_id'] ?? null,
                    'date' => $date,
                ]);
            }
        }

        // Address corrections (original -> corrected) for the proprietary cache.
        $corrections = 0;
        foreach ($parsed['corrections'] as $correction) {
            $original = $this->parseFedExAddressString($correction['original']);
            $corrected = $this->parseFedExAddressString($correction['corrected']);
            if (empty($corrected['address_1'])) {
                continue;
            }

            $this->createInvoiceLine($invoice, [
                'tracking_number' => $correction['tracking'],
                'original_address_1' => $original['address_1'],
                'original_city' => $original['city'],
                'original_state' => $original['state'],
                'original_postal' => $original['postal'],
                'original_country' => $original['country'],
                'corrected_address_1' => $corrected['address_1'],
                'corrected_city' => $corrected['city'],
                'corrected_state' => $corrected['state'],
                'corrected_postal' => $corrected['postal'],
                'corrected_country' => $corrected['country'],
                'charge_code' => 'ADDCOR',
                'charge_description' => 'Address Correction',
                'charge_amount' => 0.0,
            ]);
            $corrections++;
        }

        return [
            'total_records' => $parsed['reconciled'],
            'corrections' => $corrections,
            'new_corrections' => 0,
            'duplicates' => $parsed['skipped'],
            'total_charges' => 0.0,
        ];
    }

    /**
     * Split a FedEx correction address string ("<name/street> CITY ST ZIP CC")
     * into components, anchoring on the reliable trailing state/zip/country.
     *
     * @return array{address_1: ?string, city: ?string, state: ?string, postal: ?string, country: string}
     */
    protected function parseFedExAddressString(string $address): array
    {
        $out = ['address_1' => null, 'city' => null, 'state' => null, 'postal' => null, 'country' => 'US'];

        if (preg_match('/^(.*?)\s+([A-Za-z .\'-]+?)\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)\s+([A-Z]{2})$/', trim($address), $m)) {
            $out['address_1'] = trim($m[1]);
            $out['city'] = trim($m[2]);
            $out['state'] = $m[3];
            $out['postal'] = $m[4];
            $out['country'] = $m[5];
        } else {
            $out['address_1'] = trim($address);
        }

        return $out;
    }

    /**
     * Create invoice lines from parsed PDF corrections. Returns the count of
     * lines that carry a usable correction (both original and corrected present).
     *
     * @param  array<int, array{tracking_number: string, recorded: array<string, ?string>, corrected: array<string, ?string>}>  $corrections
     */
    public function buildCorrectionLines(CarrierInvoice $invoice, array $corrections): int
    {
        $count = 0;

        foreach ($corrections as $correction) {
            $recorded = $correction['recorded'];
            $corrected = $correction['corrected'];

            // Skip if we couldn't parse a usable corrected address.
            if (empty($corrected['address_1']) || empty($corrected['postal'])) {
                continue;
            }

            $this->createInvoiceLine($invoice, [
                'tracking_number' => $correction['tracking_number'] ?? null,
                'original_name' => $recorded['name'] ?? null,
                'original_address_1' => $recorded['address_1'] ?? null,
                'original_address_2' => $recorded['address_2'] ?? null,
                'original_city' => $recorded['city'] ?? null,
                'original_state' => $recorded['state'] ?? null,
                'original_postal' => $recorded['postal'] ?? null,
                'original_country' => 'US',
                'corrected_address_1' => $corrected['address_1'] ?? null,
                'corrected_address_2' => $corrected['address_2'] ?? null,
                'corrected_city' => $corrected['city'] ?? null,
                'corrected_state' => $corrected['state'] ?? null,
                'corrected_postal' => $corrected['postal'] ?? null,
                'corrected_country' => 'US',
                'charge_code' => 'ADC',
                'charge_description' => 'Address Correction',
                'charge_amount' => round((float) ($correction['charge_amount'] ?? 0), 2),
            ]);

            $count++;
        }

        return $count;
    }

    protected ?ChargeCategoryResolver $chargeCategoryResolver = null;

    protected ?ChargeDriverResolver $chargeDriverResolver = null;

    /**
     * Record a single fee line for fee analytics, resolving it to a canonical charge category
     * (WHAT it is) and a driver (WHY we were billed). Skips empty/zero non-charges.
     *
     * @param  array<string, mixed>  $data
     */
    protected function recordCharge(CarrierInvoice $invoice, array $data): void
    {
        $code = isset($data['charge_code']) ? trim((string) $data['charge_code']) : null;
        $description = isset($data['charge_description']) ? trim((string) $data['charge_description']) : null;
        $amount = (float) ($data['amount'] ?? 0);

        if (($code === null || $code === '') && ($description === null || $description === '')) {
            return;
        }
        if ($amount === 0.0 && empty($data['keep_zero'])) {
            return;
        }

        $this->chargeCategoryResolver ??= new ChargeCategoryResolver;
        $this->chargeDriverResolver ??= new ChargeDriverResolver;

        [$driver, $driverSource] = $this->chargeDriverResolver->resolve($code, $data['section'] ?? null, $description);

        $sourceType = $data['source_type'] ?? $this->importSourceType;
        [$categoryId, $chargeTypeId] = $this->chargeCategoryResolver->resolveDetailed($invoice->carrier_id, $code, $description, $sourceType);

        CarrierCharge::create([
            'carrier_invoice_id' => $invoice->id,
            'carrier_shipment_id' => $data['carrier_shipment_id'] ?? null,
            'carrier_id' => $invoice->carrier_id,
            'invoice_date' => $invoice->invoice_date ?? ($data['date'] ?? null),
            'ship_date' => $data['ship_date'] ?? ($data['date'] ?? null),
            'account_number' => $invoice->account_number,
            'tracking_number' => $data['tracking_number'] ?? null,
            'raw_charge_code' => $code,
            'raw_charge_description' => $description,
            'charge_category_id' => $categoryId,
            'charge_type_id' => $chargeTypeId,
            'driver' => $driver,
            'driver_source' => $driverSource,
            'amount' => $amount,
            'published' => $data['published'] ?? null,
            'incentive' => $data['incentive'] ?? null,
            'service' => $data['service'] ?? null,
            'zone' => $data['zone'] ?? null,
            'weight' => $data['weight'] ?? null,
            'source_type' => $sourceType,
        ]);
    }

    /**
     * Capture every charge on a FedEx invoice row: base transport (col 10) and
     * the repeating Charge Description/Amount pairs from $pairStart onward.
     *
     * @param  array<int, string>  $row
     */
    protected function recordFedExCharges(CarrierInvoice $invoice, array $row, int $pairStart, string $tracking, ?string $shipDate): void
    {
        $tracking = $tracking !== '' ? $tracking : null;
        $weight = $this->parseWeight($row[21] ?? ''); // col 21 = Rated Weight Amount

        // Base transportation (Transportation Charge Amount, col 10).
        $base = $this->parseAmount($row[10] ?? '0');
        if ($base !== 0.0) {
            $this->recordCharge($invoice, [
                'charge_description' => 'Transportation',
                'amount' => $base,
                'tracking_number' => $tracking,
                'date' => $shipDate,
                'weight' => $weight,
            ]);
        }

        // Repeating description/amount pairs.
        for ($i = $pairStart; $i < $pairStart + 100; $i += 2) {
            $description = trim($row[$i] ?? '');
            if ($description === '') {
                continue;
            }

            $this->recordCharge($invoice, [
                'charge_description' => $description,
                'amount' => $this->parseAmount($row[$i + 1] ?? '0'),
                'tracking_number' => $tracking,
                'date' => $shipDate,
                'weight' => $weight,
            ]);
        }
    }

    /**
     * Import a batch file into real invoices (one CarrierInvoice per invoice number)
     * with charge-level dedup. Returns the surviving invoice ids. FedEx uses the
     * split/dedup importers; other carriers fall back to the legacy per-file path.
     *
     * @return array<int, int>
     */
    public function importFile(int $carrierId, string $path, ?string $displayName = null): array
    {
        $this->lastSkipReason = null;
        $slug = strtolower(Carrier::find($carrierId)?->slug ?? '');
        $isPdf = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';

        $ids = match ($slug) {
            'fedex' => $isPdf ? $this->importFedExPdf($carrierId, $path) : $this->importFedExCsv($carrierId, $path),
            'ups' => $isPdf ? $this->importUpsPdf($carrierId, $path) : $this->importUpsCsv($carrierId, $path),
            default => (function () use ($carrierId, $path): array {
                $invoice = CarrierInvoice::create(['carrier_id' => $carrierId, 'source' => 'import', 'status' => 'pending']);
                $this->parse($invoice, $path);

                return [$invoice->id];
            })(),
        };

        // Backfill the source filename on any invoice that doesn't have one yet (the split
        // model creates invoices by number, so the batch filename isn't set at creation).
        // Use the caller's real display name — $path is a random temp file for SMB ingest
        // (e.g. smbinv_XXXX.pdf), which must never surface as the invoice's filename.
        $filename = $displayName ?? basename($path);
        CarrierInvoice::whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('filename')->orWhere('filename', ''))
            ->update(['filename' => $filename]);

        return $ids;
    }

    /**
     * Import a FedEx invoice CSV as one CarrierInvoice per real invoice number
     * (batch files hold several). Charges dedup by (tracking, category, amount)
     * against what's already on each invoice, so re-importing or later importing
     * the PDF never double-counts. Each charge keeps its own shipment date.
     *
     * @return array<int, int> invoice ids touched
     */
    public function importFedExCsv(int $carrierId, string $path): array
    {
        $this->importSourceType = 'csv';

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header) {
            throw new \RuntimeException('Empty or invalid CSV: '.$path);
        }
        $col = array_flip(array_map('trim', $header));

        $pairStart = 107;
        foreach ($header as $idx => $name) {
            if (trim((string) $name) === 'Tracking ID Charge Description') {
                $pairStart = (int) $idx;
                break;
            }
        }

        // Per-shipment date columns vary by FedEx export vintage.
        $shipDateCol = $col['Shipment Date'] ?? $col['Ship Date'] ?? $col['Tendered Date'] ?? $col['Pickup Date'] ?? null;
        $deliveryDateCol = $col['POD Delivery Date'] ?? $col['Delivery Date'] ?? null;

        // Ship-method column name varies by export; try the known ones. If none match, log the
        // header once so the real column can be added — the shipment still records (carrier + ZIP).
        $serviceCol = null;
        foreach (['Service Type', 'Ground Service', 'Service', 'Net Service', 'Service Description', 'Product', 'FedEx Service'] as $name) {
            if (isset($col[$name])) {
                $serviceCol = $col[$name];
                break;
            }
        }
        if ($serviceCol === null) {
            Log::info('FedEx CSV: no known ship-method column; shipments recorded without service.', ['headers' => array_keys($col)]);
        }

        $this->chargeCategoryResolver ??= new ChargeCategoryResolver;

        /** @var array<string, CarrierInvoice> $invoices */
        $invoices = [];
        /** @var array<int, array<string, int>> $seen */
        $seen = [];
        /** @var array<int, array<string, bool>> $corr */
        $corr = [];
        /** @var array<int, float> $fileTotals sum of the file's charge rows, per invoice */
        $fileTotals = [];
        /** @var array<int, array<string, array<string, mixed>>> $fedexShipments per-invoice, per-tracking destination */
        $fedexShipments = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $number = InvoiceIdentity::number($row[$col['Invoice Number'] ?? 3] ?? null);
            if ($number === null) {
                continue;
            }

            $date = $this->parseDate($row[$col['Invoice Date'] ?? 2] ?? '');
            $invoice = $invoices[$number.'|'.$date] ??= $this->getOrCreateInvoice(
                $carrierId,
                $number,
                $date,
                InvoiceIdentity::account($row[$col['Bill to Account Number'] ?? 1] ?? null),
            );
            $seen[$invoice->id] ??= $this->loadChargeMultiset($invoice);
            $corr[$invoice->id] ??= $this->loadCorrectionTrackings($invoice);

            $tracking = $this->parseTrackingNumber($row[$col['Express or Ground Tracking ID'] ?? 9] ?? '') ?: null;
            $shipDate = $shipDateCol !== null ? $this->parseDate($row[$shipDateCol] ?? '') : null;
            $deliveryDate = $deliveryDateCol !== null ? $this->parseDate($row[$deliveryDateCol] ?? '') : null;
            $weight = $this->parseWeight($row[21] ?? '');

            // Capture the destination (ZIP + ship method) for the shipment map, once per tracking.
            // Recipient columns are the same ones the correction path reads.
            if ($tracking !== null && ! isset($fedexShipments[$invoice->id][$tracking])) {
                $zip = trim((string) ($row[$col['Recipient Zip Code'] ?? 39] ?? ''));
                $name = trim((string) ($row[$col['Recipient Name'] ?? 33] ?? ''));
                $addr1 = trim((string) ($row[$col['Recipient Address Line 1'] ?? 35] ?? ''));
                $city = trim((string) ($row[$col['Recipient City'] ?? 37] ?? ''));
                $state = trim((string) ($row[$col['Recipient State'] ?? 38] ?? ''));
                $fedexShipments[$invoice->id][$tracking] = [
                    'zip' => $zip !== '' ? $zip : null,
                    'service' => $serviceCol !== null ? (trim((string) ($row[$serviceCol] ?? '')) ?: null) : null,
                    'receiver' => trim($name.' '.$addr1.' '.$city.' '.$state.' '.$zip) ?: null,
                    'weight' => $weight,
                    'ship_date' => $shipDate,
                ];
            }

            $items = [];
            $base = $this->parseAmount($row[10] ?? '0');
            if ($base !== 0.0) {
                $items[] = ['Transportation', $base];
            }
            for ($i = $pairStart; $i < $pairStart + 100; $i += 2) {
                $desc = trim((string) ($row[$i] ?? ''));
                if ($desc !== '') {
                    $items[] = [$desc, $this->parseAmount($row[$i + 1] ?? '0')];
                }
            }

            foreach ($items as [$desc, $amount]) {
                if ((float) $amount !== 0.0) {
                    $fileTotals[$invoice->id] = ($fileTotals[$invoice->id] ?? 0.0) + (float) $amount;
                }
                $this->mergeCharge($invoice, $seen[$invoice->id], $carrierId, $tracking, $desc, (float) $amount, $shipDate, $weight);
            }

            $this->recordFedExCorrection($invoice, $corr[$invoice->id], $col, $row, $tracking, $shipDate, $deliveryDate);
        }
        fclose($handle);

        // Persist the FedEx shipments (destination ZIP + ship method) into carrier_shipments — the
        // same table as UPS, so the shipment map/reports break out by carrier + service.
        foreach ($invoices as $inv) {
            if (! empty($fedexShipments[$inv->id])) {
                $this->persistFedExShipments($inv, $fedexShipments[$inv->id], 'csv');
            }
        }

        // CSV prints no single grand total, so reconcile against the file's own charge rows:
        // confirms we stored every charge line we read (an import-completeness check).
        return $this->finalizeInvoices($invoices, $fileTotals);
    }

    /**
     * Persist FedEx shipments into carrier_shipments (delete-then-insert per invoice + source,
     * mirroring persistUpsPdf's idempotency — carrier_shipments has no unique key).
     *
     * @param  array<string, array<string, mixed>>  $shipments  keyed by tracking number
     */
    protected function persistFedExShipments(CarrierInvoice $invoice, array $shipments, string $sourceType): void
    {
        CarrierShipment::where('carrier_invoice_id', $invoice->id)->where('source_type', $sourceType)->delete();

        foreach ($shipments as $tracking => $s) {
            CarrierShipment::create([
                'carrier_invoice_id' => $invoice->id,
                'carrier_id' => $invoice->carrier_id,
                'tracking_number' => (string) $tracking,
                'service' => $s['service'] ?? null,
                'zip' => $s['zip'] ?? null,
                'weight' => $s['weight'] ?? null,
                'ship_date' => $s['ship_date'] ?? null,
                'receiver' => $s['receiver'] ?? null,
                'source_type' => $sourceType,
            ]);
        }
    }

    /**
     * Record a FedEx address-correction line (original → corrected address, carrying
     * ship + delivery dates) for the correction cache, once per tracking number.
     *
     * @param  array<string, int>  $col
     * @param  array<int, string>  $row
     * @param  array<string, bool>  $seen
     */
    protected function recordFedExCorrection(CarrierInvoice $invoice, array &$seen, array $col, array $row, ?string $tracking, ?string $shipDate, ?string $deliveryDate): void
    {
        if ($tracking === null || isset($seen[$tracking])) {
            return;
        }
        $charge = $this->findFedExAddressCorrectionCharge($row, $col);
        if ($charge <= 0) {
            return;
        }
        $corrected1 = trim((string) ($row[$col['Recipient Address Line 1'] ?? 35] ?? ''));
        if ($corrected1 === '') {
            return;
        }

        $origAddr1 = trim((string) ($row[$col['Original Recipient Address Line 1'] ?? 58] ?? ''));
        $hasOrig = $origAddr1 !== '';

        $this->createInvoiceLine($invoice, [
            'tracking_number' => $tracking,
            'ship_date' => $shipDate,
            'delivery_date' => $deliveryDate,
            'original_name' => trim((string) ($row[$col['Recipient Name'] ?? 33] ?? '')),
            'original_company' => trim((string) ($row[$col['Recipient Company'] ?? 34] ?? '')),
            'original_address_1' => $hasOrig ? $origAddr1 : null,
            'original_address_2' => $hasOrig ? trim((string) ($row[$col['Original Recipient Address Line 2'] ?? 59] ?? '')) : null,
            'original_city' => $hasOrig ? trim((string) ($row[$col['Original Recipient City'] ?? 60] ?? '')) : null,
            'original_state' => $hasOrig ? trim((string) ($row[$col['Original Recipient State'] ?? 61] ?? '')) : null,
            'original_postal' => $hasOrig ? trim((string) ($row[$col['Original Recipient Zip Code'] ?? 62] ?? '')) : null,
            'original_country' => $hasOrig ? (trim((string) ($row[$col['Original Recipient Country/Territory'] ?? 63] ?? '')) ?: 'US') : null,
            'corrected_address_1' => $corrected1,
            'corrected_address_2' => trim((string) ($row[$col['Recipient Address Line 2'] ?? 36] ?? '')),
            'corrected_city' => trim((string) ($row[$col['Recipient City'] ?? 37] ?? '')),
            'corrected_state' => trim((string) ($row[$col['Recipient State'] ?? 38] ?? '')),
            'corrected_postal' => trim((string) ($row[$col['Recipient Zip Code'] ?? 39] ?? '')),
            'corrected_country' => trim((string) ($row[$col['Recipient Country/Territory'] ?? 40] ?? '')) ?: 'US',
            'charge_code' => 'ADDCOR',
            'charge_description' => 'Address Correction',
            'charge_amount' => $charge,
        ]);

        $seen[$tracking] = true;
    }

    /**
     * The tracking numbers that already have a correction line on this invoice.
     *
     * @return array<string, bool>
     */
    protected function loadCorrectionTrackings(CarrierInvoice $invoice): array
    {
        $set = [];
        foreach ($invoice->correctionLines()->whereNotNull('tracking_number')->pluck('tracking_number') as $t) {
            $set[(string) $t] = true;
        }

        return $set;
    }

    /**
     * Import a FedEx invoice PDF: split the batch into real invoices, dedup charges
     * by (tracking, category, amount). Blocks without a valid tracking number
     * (summary / multiweight totals) are skipped so nothing is double-counted.
     *
     * @return array<int, int> invoice ids touched
     */
    public function importFedExPdf(int $carrierId, string $path): array
    {
        $this->importSourceType = 'pdf';

        $this->chargeCategoryResolver ??= new ChargeCategoryResolver;
        $parsed = (new FedExInvoiceParser)->parseStructured($path);

        /** @var array<int, CarrierInvoice> $touched */
        $touched = [];
        /** @var array<int, float> $expectedTotals */
        $expectedTotals = [];
        /** @var array<string, CarrierInvoice> $trackingToInvoice */
        $trackingToInvoice = [];
        /** @var array<string, ?string> $trackingShipDate */
        $trackingShipDate = [];
        /** @var array<int, array<string, array<string, mixed>>> $fedexPdfShipments per-invoice destinations */
        $fedexPdfShipments = [];

        foreach ($parsed['invoices'] as $section) {
            $number = InvoiceIdentity::number($section['number']);
            if ($number === null) {
                continue;
            }

            $invoiceDate = null;
            if (! empty($section['invoice_date'])) {
                try {
                    $invoiceDate = Carbon::parse($section['invoice_date'])->toDateString();
                } catch (\Exception $e) {
                    // leave null
                }
            }

            $invoice = $this->getOrCreateInvoice($carrierId, $number, $invoiceDate, InvoiceIdentity::account($section['account'] ?? null));

            // CSV is authoritative for FedEx charges — its itemized total ties to the invoice's
            // "Net Charge Amount" column to the cent. When the CSV already imported this invoice's
            // charges, the PDF must NOT re-add them: PDF + CSV double-counts (breaking both the
            // grand total and reconciliation). Mirror importUpsPdf()'s $hasCsvCharges guard — still
            // map trackings so address-correction detail can attach, but skip PDF charge recording
            // and reconcile for a CSV-owned invoice (leave the CSV's reconciled state intact).
            $csvAuthoritative = CarrierCharge::where('carrier_invoice_id', $invoice->id)
                ->where('source_type', 'csv')->exists();

            $seen = [];
            if (! $csvAuthoritative) {
                $expectedTotals[$invoice->id] = ($expectedTotals[$invoice->id] ?? 0.0) + (float) ($section['expected_total'] ?? 0);
                $seen = $this->loadChargeMultiset($invoice);
                $touched[$invoice->id] = $invoice;
            }

            foreach ($section['shipments'] as $shipment) {
                $tracking = (string) ($shipment['tracking_id'] ?? '');
                if (! preg_match('/^\d{12,22}$/', $tracking)) {
                    continue;
                }
                $shipDate = null;
                if (! empty($shipment['ship_date'])) {
                    try {
                        $shipDate = Carbon::parse($shipment['ship_date'])->toDateString();
                    } catch (\Exception $e) {
                        // leave null
                    }
                }
                if (! $csvAuthoritative) {
                    foreach ($shipment['charge_ledger'] as $charge) {
                        $this->mergeCharge($invoice, $seen, $carrierId, $tracking, (string) $charge['description'], (float) $charge['amount'], $shipDate, null);
                    }

                    // Capture destination ZIP + ship method for the shipment map (PDF-owned invoices
                    // only; a CSV-owned invoice already recorded its shipments from the CSV).
                    $recvText = trim(implode(' ', array_map('trim', $shipment['recipient'] ?? [])));
                    $zip = preg_match_all('/\b\d{5}\b/', $recvText, $mm) ? end($mm[0]) : null;
                    $fedexPdfShipments[$invoice->id][$tracking] = [
                        'zip' => $zip,
                        'service' => $shipment['service_type'] ?? null,
                        'receiver' => $recvText ?: null,
                        'ship_date' => $shipDate,
                    ];
                }
                $trackingToInvoice[$tracking] = $invoice;
                $trackingShipDate[$tracking] = $shipDate;
            }
        }

        // Persist FedEx PDF shipments (destination ZIP + ship method) for the invoices this PDF owns.
        foreach ($touched as $inv) {
            if (! empty($fedexPdfShipments[$inv->id])) {
                $this->persistFedExShipments($inv, $fedexPdfShipments[$inv->id], 'pdf');
            }
        }

        // Address corrections (Ground Address Correction section) → route to the
        // invoice owning each tracking, deduped, carrying the shipment's ship date.
        $corrSeen = [];
        foreach ($parsed['corrections'] as $correction) {
            $tracking = (string) ($correction['tracking'] ?? '');
            $invoice = $trackingToInvoice[$tracking] ?? null;
            if ($invoice === null) {
                continue;
            }
            $corrSeen[$invoice->id] ??= $this->loadCorrectionTrackings($invoice);
            if (isset($corrSeen[$invoice->id][$tracking])) {
                continue;
            }

            $original = $this->parseFedExAddressString((string) ($correction['original'] ?? ''));
            $corrected = $this->parseFedExAddressString((string) ($correction['corrected'] ?? ''));
            if (empty($corrected['address_1'])) {
                continue;
            }

            $this->createInvoiceLine($invoice, [
                'tracking_number' => $tracking,
                'ship_date' => $trackingShipDate[$tracking] ?? null,
                'original_address_1' => $original['address_1'],
                'original_city' => $original['city'],
                'original_state' => $original['state'],
                'original_postal' => $original['postal'],
                'original_country' => $original['country'],
                'corrected_address_1' => $corrected['address_1'],
                'corrected_city' => $corrected['city'],
                'corrected_state' => $corrected['state'],
                'corrected_postal' => $corrected['postal'],
                'corrected_country' => $corrected['country'],
                'charge_code' => 'ADDCOR',
                'charge_description' => 'Address Correction',
                'charge_amount' => 0.0,
            ]);
            $corrSeen[$invoice->id][$tracking] = true;
        }

        // PDF prints a per-invoice grand total (sum of shipment "Total Charge" markers) —
        // reconcile against it, and back-fill FedEx original addresses along the way.
        return $this->finalizeInvoices($touched, $expectedTotals);
    }

    /**
     * Import a UPS invoice CSV (UPS Billing Data, one invoice per file) — get-or-create
     * the invoice by number (col 5), record every charge line with its own ship date,
     * and build address-correction lines from the ADC rows. Charges dedup by
     * (tracking, category, amount).
     *
     * @return array<int, int> invoice ids touched
     */
    public function importUpsCsv(int $carrierId, string $path): array
    {
        $this->importSourceType = 'csv';
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $this->chargeCategoryResolver ??= new ChargeCategoryResolver;

        /** @var array<string, CarrierInvoice> $invoices */
        $invoices = [];
        $seen = [];
        $corr = [];
        /** @var array<int, float> $fileTotals sum of the file's charge rows, per invoice */
        $fileTotals = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $number = InvoiceIdentity::number($row[5] ?? null);
            if ($number === null) {
                continue;
            }

            $date = $this->parseDate($row[4] ?? '');
            $invoice = $invoices[$number.'|'.$date] ??= $this->getOrCreateInvoice(
                $carrierId,
                $number,
                $date,
                InvoiceIdentity::account($row[1] ?? null),
            );
            if (! isset($seen[$invoice->id])) {
                // CSV is authoritative: on first touch, evict any PDF-sourced charges for
                // this invoice (a PDF may have imported first) so CSV owns the charges.
                // Shipment/audit rows from the PDF are kept.
                CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'pdf')->delete();
                $seen[$invoice->id] = $this->loadChargeMultiset($invoice);
            }
            $corr[$invoice->id] ??= $this->loadCorrectionTrackings($invoice);

            $tracking = trim((string) ($row[13] ?? '')) ?: null;
            $shipDate = $this->parseDate($row[11] ?? '');
            $code = trim((string) ($row[35] ?? ''));
            $amount = $this->parseAmount($row[52] ?? '0');
            if ($amount !== 0.0) {
                $fileTotals[$invoice->id] = ($fileTotals[$invoice->id] ?? 0.0) + $amount;
            }

            $this->mergeChargeRow($invoice, $seen[$invoice->id], $carrierId, [
                'charge_code' => $code,
                'charge_description' => trim((string) ($row[45] ?? '')),
                'amount' => $amount,
                'tracking_number' => $tracking,
                'ship_date' => $shipDate,
                'zone' => trim((string) ($row[33] ?? '')) ?: null,
                'weight' => $this->parseWeight($row[28] ?? ''),
            ]);

            if ($code === 'ADC' && $tracking !== null && ! isset($corr[$invoice->id][$tracking])) {
                $origAddr1 = trim((string) ($row[68] ?? ''));
                $corrAddr1 = trim((string) ($row[76] ?? ''));
                if ($origAddr1 !== '' || $corrAddr1 !== '') {
                    $this->createInvoiceLine($invoice, [
                        'tracking_number' => $tracking,
                        'ship_date' => $shipDate,
                        'original_name' => trim((string) ($row[66] ?? '')),
                        'original_company' => trim((string) ($row[67] ?? '')),
                        'original_address_1' => $origAddr1,
                        'original_address_2' => trim((string) ($row[69] ?? '')),
                        'original_city' => trim((string) ($row[70] ?? '')),
                        'original_state' => trim((string) ($row[71] ?? '')),
                        'original_postal' => trim((string) ($row[72] ?? '')),
                        'original_country' => trim((string) ($row[73] ?? '')) ?: 'US',
                        'corrected_address_1' => $corrAddr1,
                        'corrected_address_2' => trim((string) ($row[77] ?? '')),
                        'corrected_city' => trim((string) ($row[78] ?? '')),
                        'corrected_state' => trim((string) ($row[79] ?? '')),
                        'corrected_postal' => trim((string) ($row[80] ?? '')),
                        'corrected_country' => trim((string) ($row[81] ?? '')) ?: 'US',
                        'charge_code' => 'ADC',
                        'charge_description' => 'Address Correction',
                        'charge_amount' => $this->parseAmount($row[52] ?? '0'),
                    ]);
                    $corr[$invoice->id][$tracking] = true;
                }
            }
        }
        fclose($handle);

        // Link the just-imported CSV charges to their shipment rows (if the PDF has already
        // provided them) and fill the per-shipment cost split, so the Per-Shipment Costs view
        // reconciles. When the PDF hasn't arrived yet this is a no-op; importUpsPdf runs the
        // same step once it creates the shipments.
        foreach ($invoices as $invoice) {
            $this->linkCsvChargesToShipments($invoice);
            try {
                app(FedExShipmentDeriveService::class)->enrichCostsForInvoice($invoice);
            } catch (\Throwable $e) {
                Log::warning('UPS CSV shipment cost enrich failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        // CSV prints no single grand total, so reconcile against the file's own charge rows:
        // confirms we stored every charge line we read (an import-completeness check).
        return $this->finalizeInvoices($invoices, $fileTotals);
    }

    /**
     * Attach an invoice's unlinked charges (i.e. CSV-sourced — CSV has no per-shipment
     * structure) to a shipment row by tracking number, so per-shipment cost can be
     * computed. A tracking can span several shipment rows (outbound + correction sections);
     * CSV can't tell them apart, so the whole tracking's charges go to the PRIMARY row
     * (outbound preferred, then inbound, then lowest id). The tracking total stays exact;
     * only the per-section split is approximate for CSV invoices. Returns charges linked.
     */
    protected function linkCsvChargesToShipments(CarrierInvoice $invoice): int
    {
        $trackings = CarrierCharge::query()
            ->where('carrier_invoice_id', $invoice->id)
            ->whereNull('carrier_shipment_id')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '<>', '')
            ->distinct()
            ->pluck('tracking_number');

        if ($trackings->isEmpty()) {
            return 0;
        }

        $primary = $invoice->shipments()
            ->whereIn('tracking_number', $trackings)
            ->orderByRaw("CASE WHEN section = 'outbound' THEN 0 WHEN section = 'inbound' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get(['id', 'tracking_number'])
            ->groupBy('tracking_number')
            ->map(fn ($group) => $group->first()->id);

        $linked = 0;
        foreach ($primary as $tracking => $shipmentId) {
            $linked += CarrierCharge::query()
                ->where('carrier_invoice_id', $invoice->id)
                ->whereNull('carrier_shipment_id')
                ->where('tracking_number', $tracking)
                ->update(['carrier_shipment_id' => $shipmentId]);
        }

        return $linked;
    }

    /**
     * Import a UPS invoice PDF. The CSV is the charge data source, so we don't extract
     * charges from the PDF — we get-or-create the invoice by number (so a PDF-only
     * invoice isn't lost) and let it link as a source file for download. Paired PDFs
     * simply attach to the invoice the CSV already created.
     *
     * @return array<int, int>
     */
    public function importUpsPdf(int $carrierId, string $path): array
    {
        $this->importSourceType = 'pdf';
        $text = (new PdfTextExtractor)->extractFile($path);

        $parsed = (new UpsPdfChargeParser)->parse($text);
        if ($parsed['invoice_number'] === null) {
            $this->lastSkipReason = 'unparseable';

            return [];
        }
        $number = InvoiceIdentity::number($parsed['invoice_number']);
        if ($number === null) {
            $this->lastSkipReason = 'unparseable';

            return [];
        }

        // Legacy UPS PDFs (pre-~2016 Ricoh layout — "Shipper Number" instead of "Account
        // Number") don't match the current-format parser: they yield a garbage number like
        // "PAYMENTS" and, at most, tiny mis-parse artifact charges. Every current-format UPS
        // PDF prints a "Charges this period $X" summary; the legacy layout never does, so its
        // absence is the reliable discriminator. Without it we also can't reconcile. The CSV
        // is authoritative for those years, so skip rather than create a junk invoice.
        $grandTotal = $this->extractPdfGrandTotal($text);
        if ($grandTotal === null) {
            $this->lastSkipReason = 'legacy_format';
            Log::info('Skipped legacy-format UPS PDF (no charges-this-period summary, CSV is authoritative)', [
                'file' => basename($path),
                'parsed_number' => $parsed['invoice_number'],
            ]);

            return [];
        }

        $invoice = $this->getOrCreateInvoice(
            $carrierId,
            $number,
            $parsed['invoice_date'],
            InvoiceIdentity::account($parsed['account_number']),
        );

        $this->persistUpsPdf($invoice, $parsed, $grandTotal);

        // Fill each shipment's cost (total + base/fee split) from its charges so the
        // Per-Shipment Costs view totals the real per-shipment cost (not the PDF's mostly-null
        // printed Total). When the charges are CSV-sourced (CSV owns them), link them to these
        // just-created shipment rows first. Best-effort — never fail the import.
        try {
            $this->linkCsvChargesToShipments($invoice);
            app(FedExShipmentDeriveService::class)->enrichCostsForInvoice($invoice);
        } catch (\Throwable $e) {
            Log::warning('UPS shipment cost enrich failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }

        // Feed the address-correction cache from the same PDF (reuses the tested parser).
        $corrections = (new UpsPdfInvoiceParser)->parse($text)['corrections'];
        if ($corrections !== []) {
            // Idempotent re-import: clear prior address-correction lines for this invoice
            // before rebuilding, so a re-parse doesn't duplicate them (charges/shipments are
            // already cleared in persistUpsPdf; correction lines live in a separate table).
            CarrierInvoiceLine::where('carrier_invoice_id', $invoice->id)->where('charge_code', 'ADC')->delete();
            $this->buildCorrectionLines($invoice, $corrections);
        }

        // Link corrections into the shared cache and stamp the summary counters shown on
        // the invoice (shipments, corrections, new mappings, total charges).
        $newCorrections = $this->linkCorrectionsToCache($invoice);
        $this->refreshInvoiceTotals($invoice, $newCorrections);

        // UPS PDF has its own inline finalize above (it does NOT call finalizeInvoices()), so
        // fan out the post-import jobs here — otherwise PDF imports never push chargebacks or
        // sync carton costs the way the CSV/FedEx paths do.
        if ($invoice->charges()->count() > 0) {
            $this->dispatchPostImportJobs([$invoice->id]);
        }

        return [$invoice->id];
    }

    /**
     * Persist parsed UPS-PDF shipments + charges, idempotently (a re-import replaces the
     * prior PDF-sourced rows for this invoice), then stamp the reconciliation result.
     *
     * @param  array<string, mixed>  $parsed
     */
    protected function persistUpsPdf(CarrierInvoice $invoice, array $parsed, ?float $expected): void
    {
        // Idempotent re-import: clear prior PDF rows for this invoice before re-inserting.
        CarrierCharge::where('carrier_invoice_id', $invoice->id)->where('source_type', 'pdf')->delete();
        CarrierShipment::where('carrier_invoice_id', $invoice->id)->delete();

        // CSV is the authoritative charge source (matched by invoice NUMBER, not filename).
        // If this invoice already carries CSV charges, we keep the PDF's shipment/audit
        // detail but do NOT add its charges — avoiding a double-count when the same invoice
        // arrives as both a .csv and a .pdf.
        $hasCsvCharges = CarrierCharge::where('carrier_invoice_id', $invoice->id)
            ->where('source_type', 'csv')->exists();

        $this->chargeCategoryResolver ??= new ChargeCategoryResolver;

        foreach ($parsed['shipments'] as $s) {
            $shipment = CarrierShipment::create([
                'carrier_invoice_id' => $invoice->id,
                'carrier_id' => $invoice->carrier_id,
                'tracking_number' => $s['tracking_number'],
                'section' => $s['section'],
                'service' => $s['service'],
                'zip' => $s['zip'],
                'zone' => $s['zone'],
                'weight' => $s['weight'],
                'billed_weight' => $s['billed_weight'],
                'ship_date' => $s['ship_date'],
                'customer_dims' => $s['customer_dims'],
                'audited_dims' => $s['audited_dims'],
                'customer_weight' => $s['customer_weight'],
                'message_codes' => $s['message_codes'] !== [] ? $s['message_codes'] : null,
                // Cap free-text address fields — a mis-parsed legacy PDF can dump a huge
                // blob here; the real values are short.
                'sender' => $this->capText($s['sender'], 500),
                'receiver' => $this->capText($s['receiver'], 500),
                'third_party' => $this->capText($s['third_party'], 500),
                'is_third_party' => $s['is_third_party'],
                'printed_total' => $s['printed_total'],
                'source_type' => 'pdf',
            ]);

            // Zero-amount shipments (e.g. third-party billed) get a shipment row for the
            // ref/address data, but no charge rows — no cost to attribute. And when CSV
            // already owns this invoice's charges, skip PDF charges entirely.
            if ($hasCsvCharges) {
                continue;
            }
            foreach ($s['charges'] as $c) {
                if ((float) $c['amount'] === 0.0) {
                    continue;
                }
                $this->recordCharge($invoice, [
                    'carrier_shipment_id' => $shipment->id,
                    'charge_description' => $c['description'],
                    'amount' => $c['amount'],
                    'published' => $c['published'] ?? null,
                    'incentive' => $c['incentive'] ?? null,
                    'section' => $s['section'],
                    'tracking_number' => $s['tracking_number'],
                    'ship_date' => $s['ship_date'],
                    'service' => $s['service'],
                    'zone' => $s['zone'],
                    'weight' => $s['weight'],
                    'source_type' => 'pdf',
                ]);
            }
        }

        foreach ($hasCsvCharges ? [] : $parsed['account_charges'] as $c) {
            if ((float) $c['amount'] === 0.0) {
                continue;
            }
            $this->recordCharge($invoice, [
                'charge_description' => $c['description'],
                'amount' => $c['amount'],
                'source_type' => 'pdf',
            ]);
        }

        // Residual safety-net: if we still fall short of the printed grand total (a fee type
        // neither structural parsing nor labeled-fee capture recognized), record the remainder
        // as ONE flagged line so no money is lost and the invoice reconciles. The line is its
        // own review queue — a persistent residual means a new fee shape to teach the parser.
        if (! $hasCsvCharges && $expected !== null) {
            $stored = (float) $invoice->charges()->sum('amount');
            $residual = round($expected - $stored, 2);
            if (abs($residual) > 0.01) {
                // Positive = a fee we didn't recognize; negative = a credit/adjustment we
                // missed (e.g. a Residential/Commercial reclassification refund). Either way,
                // record it as one flagged line so the invoice reconciles and the gap is
                // visible for review.
                $this->recordCharge($invoice, [
                    'charge_description' => $residual > 0
                        ? 'UPS charge (unclassified — review)'
                        : 'UPS credit/adjustment (unclassified — review)',
                    'amount' => $residual,
                    'source_type' => 'pdf',
                ]);
            }
        }

        $invoice->update(['status' => 'completed', 'processed_at' => $invoice->processed_at ?? now()]);
        $this->reconcileInvoice($invoice, (float) $invoice->charges()->sum('amount'), $expected);
    }

    /**
     * Stamp the invoice-level reconciliation result: does our parsed charge sum match the
     * grand total the carrier PRINTED on the invoice? Carrier-agnostic — the caller supplies
     * the printed expected total (null for CSV, which has no printed total to check against).
     */
    protected function reconcileInvoice(CarrierInvoice $invoice, float $parsedTotal, ?float $expected): void
    {
        $reconciled = $expected !== null ? abs($parsedTotal - $expected) < 0.01 : null;

        $invoice->update([
            'charges_parsed_total' => round($parsedTotal, 2),
            'charges_expected_total' => $expected,
            'charges_reconciled' => $reconciled,
        ]);

        if ($expected !== null && $reconciled === false) {
            Log::warning('Invoice charges did not reconcile', [
                'carrier_invoice_id' => $invoice->id,
                'invoice' => $invoice->invoice_number,
                'parsed' => round($parsedTotal, 2),
                'expected' => $expected,
            ]);
        }
    }

    /**
     * Shared finalize step for the split-model importers: drop zero-charge invoices,
     * back-fill FedEx original addresses from the shipping DB, link corrections to the
     * cache, refresh summary counters, and (for PDFs, which print a grand total) reconcile.
     *
     * @param  array<int|string, CarrierInvoice>  $invoices
     * @param  array<int, float>  $expectedTotals  invoice id => printed grand total (PDF only)
     * @return array<int, int>
     */
    protected function finalizeInvoices(array $invoices, array $expectedTotals = []): array
    {
        $survived = [];
        foreach ($invoices as $invoice) {
            if ($invoice->charges()->count() === 0) {
                $invoice->delete();

                continue;
            }

            // Back-fill missing original addresses (FedEx returns/undeliverables often ship
            // without one) BEFORE linking, so cache variants use the real customer address.
            if (strtolower((string) $invoice->carrier?->slug) === 'fedex' && $this->shippingDb->isAvailable()) {
                $this->backfillFedExOriginalAddresses($invoice);
            }

            $newCorrections = $this->linkCorrectionsToCache($invoice);
            $this->refreshInvoiceTotals($invoice, $newCorrections);
            $this->attributeCorrectionSurcharges($invoice);

            if (array_key_exists($invoice->id, $expectedTotals)) {
                $this->reconcileInvoice($invoice, (float) $invoice->charges()->sum('amount'), $expectedTotals[$invoice->id]);
            }

            $survived[] = $invoice->id;
        }

        $this->dispatchPostImportJobs($survived);

        return $survived;
    }

    /**
     * A tracking whose charges include a correction (address/audit) but NO base transport is a
     * correction-only adjustment line — its surcharges (fuel, DAS, residential) are part of the
     * correction, not a base shipment. UPS carries this via the PDF section; FedEx has no sections,
     * so its correction fuel would otherwise stay driver=normal and never charge back. Inherit the
     * correction driver onto the tracking's remaining `normal` charges so they become chargeback-
     * eligible. Category is untouched — only the "why" (driver) changes — so the fuel still books to
     * its own cost center (e.g. 72520) while the fee books to 72510.
     */
    protected function attributeCorrectionSurcharges(CarrierInvoice $invoice): void
    {
        $baseCategoryId = (int) (DB::table('charge_categories')->where('name', 'Base Transportation')->value('id') ?? 0);
        $correctionDrivers = [ChargeDriver::AddressCorrection->value, ChargeDriver::AuditCorrection->value];

        $charges = $invoice->charges()
            ->whereNotNull('tracking_number')->where('tracking_number', '<>', '')
            ->get(['id', 'tracking_number', 'driver', 'charge_category_id']);

        foreach ($charges->groupBy('tracking_number') as $rows) {
            $correctionDriver = $rows->first(fn ($r): bool => in_array($r->driver, $correctionDrivers, true))?->driver;
            if ($correctionDriver === null) {
                continue;
            }
            // A tracking that also has base transport is a real shipment — its surcharges belong to
            // that shipment, not the correction, so leave them alone.
            if ($rows->contains(fn ($r): bool => (int) $r->charge_category_id === $baseCategoryId)) {
                continue;
            }
            $ids = $rows->filter(fn ($r): bool => $r->driver === ChargeDriver::Normal->value)->pluck('id')->all();
            if ($ids !== []) {
                CarrierCharge::whereIn('id', $ids)->update(['driver' => $correctionDriver, 'driver_source' => 'correction_sibling']);
            }
        }
    }

    /**
     * Post-import fan-out shared by every import path: sync each invoice's Pace carton ship costs
     * into the recoup baseline mirror, and push eligible carrier charges back to Pace as JobCost
     * chargebacks. Both are queued (a live Pace API call must never block the import) and the
     * chargeback push is a no-op unless the master toggle is on. IMPORTANT: importUpsPdf() has its
     * own inline finalize and does NOT go through finalizeInvoices(), so it calls this directly —
     * otherwise UPS PDF imports would never sync carton costs or push chargebacks.
     *
     * @param  array<int, int>  $invoiceIds
     */
    protected function dispatchPostImportJobs(array $invoiceIds): void
    {
        if ($invoiceIds === []) {
            return;
        }

        SyncInvoiceCartonCosts::dispatch($invoiceIds);
        PushInvoiceChargebacks::dispatch($invoiceIds);
    }

    /**
     * The invoice grand total UPS prints in the "Summary of Charges" ("Charges this period
     * $ 4,411.77"), used to reconcile our parsed sum.
     */
    protected function extractPdfGrandTotal(string $text): ?float
    {
        if (preg_match('/Charges this period\s*\$?\s*([\d,]+\.\d{2})/', $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    /**
     * Truncate a free-text value to a sane column width (null stays null).
     */
    protected function capText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Add a charge (with full column data: code/zone/weight) unless an identical one
     * (same tracking + category + amount) is already on the invoice.
     *
     * @param  array<string, int>  $seen
     * @param  array<string, mixed>  $data
     */
    protected function mergeChargeRow(CarrierInvoice $invoice, array &$seen, int $carrierId, array $data): void
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount === 0.0) {
            return;
        }
        $categoryId = $this->chargeCategoryResolver->resolve($carrierId, $data['charge_code'] ?? null, $data['charge_description'] ?? null, $data['source_type'] ?? $this->importSourceType);
        $key = $this->chargeKey($data['tracking_number'] ?? null, $categoryId, $amount, $data['ship_date'] ?? null);

        if (($seen[$key] ?? 0) > 0) {
            $seen[$key]--;

            return;
        }

        $this->recordCharge($invoice, $data);
    }

    /**
     * Dedup key for a charge: tracking + category + amount + ship date. Ship date is
     * included because carriers recycle tracking numbers over time — without it, a
     * recycled tracking with the same category+amount in a later period would be
     * dropped as a false duplicate.
     */
    private function chargeKey(?string $tracking, ?int $categoryId, float $amount, ?string $shipDate): string
    {
        return ((string) $tracking).'|'.($categoryId ?? 'n').'|'.number_format($amount, 2).'|'
            .($shipDate !== null && $shipDate !== '' ? substr($shipDate, 0, 10) : '');
    }

    /**
     * Add a charge unless an identical one (same tracking + category + amount +
     * ship date) is already on the invoice — the multiset-difference that keeps
     * CSV/PDF merges cost-safe. $seen is the running multiset for this invoice.
     *
     * @param  array<string, int>  $seen
     */
    protected function mergeCharge(CarrierInvoice $invoice, array &$seen, int $carrierId, ?string $tracking, string $description, float $amount, ?string $shipDate, ?float $weight): void
    {
        if ($amount === 0.0) {
            return;
        }
        $categoryId = $this->chargeCategoryResolver->resolve($carrierId, null, $description, $this->importSourceType);
        $key = $this->chargeKey($tracking, $categoryId, $amount, $shipDate);

        if (($seen[$key] ?? 0) > 0) {
            $seen[$key]--;

            return;
        }

        $this->recordCharge($invoice, [
            'charge_description' => $description,
            'amount' => $amount,
            'tracking_number' => $tracking,
            'ship_date' => $shipDate,
            'weight' => $weight,
        ]);
    }

    /**
     * Get or create the CarrierInvoice for a normalized invoice number, backfilling
     * date/account when first learned.
     */
    protected function getOrCreateInvoice(int $carrierId, string $invoiceNumber, ?string $invoiceDate, ?string $account): CarrierInvoice
    {
        // Invoice identity is (carrier, number, date). UPS recycles the invoice-number
        // series roughly every ~10 years, so number alone merged e.g. the 2009 and 2019
        // "E540W079" into one record. Including the billing date keeps recycled numbers
        // separate while still merging same-period duplicates (weekly file + year file),
        // and a matching unique index makes the regression impossible.
        // createOrFirst (not firstOrCreate) so concurrent per-file import jobs racing on
        // the same new invoice don't spuriously fail: the loser catches the unique-index
        // violation and re-selects instead of throwing.
        $invoice = CarrierInvoice::createOrFirst(
            ['carrier_id' => $carrierId, 'invoice_number' => $invoiceNumber, 'invoice_date' => $invoiceDate],
            ['account_number' => $account, 'source' => 'import', 'status' => 'completed'],
        );

        if ($account !== null && empty($invoice->account_number)) {
            $invoice->update(['account_number' => $account]);
        }

        return $invoice;
    }

    /**
     * Build the (tracking|category|amount => count) multiset of the charges already
     * on an invoice, used to dedup incoming charges.
     *
     * @return array<string, int>
     */
    protected function loadChargeMultiset(CarrierInvoice $invoice): array
    {
        $seen = [];
        foreach ($invoice->charges()->get(['tracking_number', 'charge_category_id', 'amount', 'ship_date']) as $charge) {
            $key = $this->chargeKey(
                $charge->tracking_number,
                $charge->charge_category_id,
                (float) $charge->amount,
                $charge->ship_date?->format('Y-m-d'),
            );
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }

        return $seen;
    }

    /**
     * Recompute stored counters on an invoice from its charges.
     */
    /**
     * Recompute the stored summary counters shown on the invoice from its charges,
     * shipments and correction lines. `total_records` = shipment count when we have
     * shipment rows (UPS PDF), else the distinct tracking count on charges.
     */
    protected function refreshInvoiceTotals(CarrierInvoice $invoice, int $newCorrections = 0): void
    {
        $shipmentCount = $invoice->shipments()->count();
        $correctionRecords = $invoice->correctionLines()->count();

        $invoice->update([
            'total_records' => $shipmentCount > 0
                ? $shipmentCount
                : $invoice->charges()->distinct('tracking_number')->count('tracking_number'),
            'correction_records' => $correctionRecords,
            'new_corrections' => $newCorrections,
            'duplicate_corrections' => max(0, $correctionRecords - $newCorrections),
            'total_correction_charges' => (float) $invoice->charges()->sum('amount'),
            'processed_at' => $invoice->processed_at ?? now(),
            'status' => 'completed',
        ]);
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
            'original_name' => $this->cap($data['original_name'] ?? null, 100),
            'original_company' => $this->cap($data['original_company'] ?? null, 100),
            'original_address_1' => $this->cap($data['original_address_1'] ?? null, 100),
            'original_address_2' => $this->cap($data['original_address_2'] ?? null, 100),
            'original_address_3' => $this->cap($data['original_address_3'] ?? null, 100),
            'original_city' => $this->cap($data['original_city'] ?? null, 50),
            'original_state' => $this->cap($data['original_state'] ?? null, 50),
            'original_postal' => $this->cap($data['original_postal'] ?? null, 20),
            'original_country' => $this->cap($data['original_country'] ?? 'US', 2),
            'corrected_address_1' => $this->cap($data['corrected_address_1'] ?? null, 100),
            'corrected_address_2' => $this->cap($data['corrected_address_2'] ?? null, 100),
            'corrected_address_3' => $this->cap($data['corrected_address_3'] ?? null, 100),
            'corrected_city' => $this->cap($data['corrected_city'] ?? null, 50),
            'corrected_state' => $this->cap($data['corrected_state'] ?? null, 50),
            'corrected_postal' => $this->cap($data['corrected_postal'] ?? null, 20),
            'corrected_country' => $this->cap($data['corrected_country'] ?? 'US', 2),
            'charge_code' => $data['charge_code'] ?? null,
            'charge_description' => $data['charge_description'] ?? null,
            'charge_amount' => $data['charge_amount'] ?? 0.0,
        ]);
    }

    /**
     * Trim a value to fit its varchar column — guards against a parser that
     * over-captures (e.g. a malformed address spilling into the city field),
     * which would otherwise abort the whole invoice on a "data too long" error.
     */
    private function cap(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    /**
     * Supplement an already-imported invoice with shipments that exist only in
     * the PDF (FedEx CSV exports occasionally omit a shipment the PDF has).
     *
     * Cost-safe by construction: a PDF shipment is added ONLY when it carries a
     * valid tracking number that is not already on the invoice. Blocks without a
     * real tracking number — summary / multiweight / payor-type totals — are
     * skipped, so a charge can never be counted twice.
     */
    public function supplementFromPdf(CarrierInvoice $invoice, string $pdfPath): int
    {
        if (strtolower($invoice->carrier->slug ?? '') !== 'fedex') {
            return 0;
        }

        $parsed = (new FedExInvoiceParser)->parse($pdfPath);

        $existing = $invoice->charges()
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '<>', '')
            ->pluck('tracking_number')
            ->map(fn ($t): string => (string) $t)
            ->flip();

        $added = 0;
        foreach ($parsed['shipments'] as $shipment) {
            $tracking = (string) ($shipment['tracking_id'] ?? '');
            if (! preg_match('/^\d{12,22}$/', $tracking) || $existing->has($tracking)) {
                continue;
            }

            $date = null;
            if (! empty($shipment['ship_date'])) {
                try {
                    $date = Carbon::parse($shipment['ship_date'])->toDateString();
                } catch (\Exception $e) {
                    // leave null
                }
            }

            foreach ($shipment['charge_ledger'] as $charge) {
                $this->recordCharge($invoice, [
                    'charge_description' => $charge['description'],
                    'amount' => $charge['amount'],
                    'tracking_number' => $tracking,
                    'date' => $date,
                ]);
            }

            $existing->put($tracking, true);
            $added++;
        }

        return $added;
    }
}
