<?php

namespace App\Services\Invoices;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Production FedEx invoice PDF parser built on smalot/pdfparser (2s / 34MB on a
 * 371-page invoice), using a state-machine over per-shipment blocks.
 *
 *  1. Split on "Ship Date:" so each block is ONE shipment (kills cross-shipment
 *     "bleed" that breaks line-by-line scanners).
 *  2. Per block: detect Express vs Ground, isolate the financial region, and
 *     extract a granular charge_ledger of {description, amount}.
 *       - Express lays charges out as "<noise>\t<Label>\t<Amount>" — the label
 *         is the field before the amount, so no dictionary is needed.
 *       - Ground groups labels then amounts; we pair them by order (dictionary,
 *         with a positional fallback for unknown labels).
 *  3. Reconcile gate: the ledger must sum to the printed Total Charge or the
 *     block is logged and skipped — data never silently corrupts the DB.
 *  4. The "Ground Address Correction" section yields original -> corrected
 *     address pairs (proprietary cache; no Pace/ProcessShipper API needed).
 */
class FedExInvoiceParser
{
    /**
     * Known Ground charge labels, longest-first for greedy matching.
     *
     * @var array<int, string>
     */
    protected array $labels = [
        'Regularly Scheduled Pickup Mon-Fri',
        'Out of Delivery Area Tier',
        'DAS Extended Commercial',
        'DAS Extended Comm',
        'DAS Remote Comm',
        'DAS HI Residential',
        'DAS Extended Resi',
        'DAS Comm',
        'DAS Resi',
        'DAS',
        'Transportation Charge',
        'Performance Pricing',
        'Earned Discount',
        'Fuel Surcharge',
        'Address Correction',
        'Demand-Oversize',
        "Demand-Add'l Handling",
        'Demand Surcharge',
        'Oversize Charge',
        'AHS - Dimensions',
        'AHS - Weight',
        "Add'l Handling-Dimension",
        'Additional Handling',
        'Residential',
        'Weekday Delivery',
        'Discount',
    ];

    public function parse(string $path): array
    {
        return $this->parseText((new Parser)->parseFile($path)->getText(), basename($path));
    }

    /**
     * @return array{meta: array<string, ?string>, shipments: array<int, array<string, mixed>>, reconciled: int, skipped: int, corrections: array<int, array<string, string>>}
     */
    public function parseText(string $text, string $source = ''): array
    {
        // FedEx delimits each shipment block with "Ship Date:" (current format)
        // or "Pickup Date:" (2010-2015 invoices) at the shipment start. The
        // earliest invoices (2009-era) have no date delimiter, so we fall back
        // to segmenting on the per-shipment Total marker.
        $ship = substr_count($text, 'Ship Date:');
        $pickup = substr_count($text, 'Pickup Date:');
        if ($ship > 0 || $pickup > 0) {
            $blocks = explode($pickup > $ship ? 'Pickup Date:' : 'Ship Date:', $text);
            array_shift($blocks); // master header block
        } else {
            $blocks = $this->splitByTotalMarker($text);
        }

        $shipments = [];
        $reconciled = 0;
        $skipped = 0;

        foreach ($blocks as $block) {
            $shipment = $this->parseBlock($block, $source);
            if ($shipment === null) {
                $skipped++;

                continue;
            }
            $shipments[] = $shipment;
            $reconciled++;
        }

        return [
            'meta' => $this->extractMeta($text),
            'shipments' => $shipments,
            'reconciled' => $reconciled,
            'skipped' => $skipped,
            'corrections' => $this->extractCorrections($text),
        ];
    }

    /**
     * Parse a batch PDF into its constituent invoices — a file holds several,
     * each delimited by "Invoice Number X-XXX-XXXXX". Each invoice carries its own
     * number/date/account and its shipments (each with its own ship date).
     *
     * @return array<int, array{number: string, invoice_date: ?string, account: ?string, shipments: array<int, array<string, mixed>>}>
     */
    public function parseStructured(string $path): array
    {
        return $this->splitBySection((new Parser)->parseFile($path)->getText(), basename($path));
    }

    /**
     * @return array<int, array{number: string, invoice_date: ?string, account: ?string, shipments: array<int, array<string, mixed>>}>
     */
    protected function splitBySection(string $text, string $source): array
    {
        $parts = preg_split('/Invoice Number\s+([0-9]-[0-9]{3}-[0-9]{5})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        // No per-invoice sections detected — treat the whole document as one invoice.
        if (! is_array($parts) || count($parts) < 3) {
            $meta = $this->extractMeta($text);

            return [[
                'number' => (string) ($meta['invoice_number'] ?? ''),
                'invoice_date' => $meta['invoice_date'] ?? null,
                'account' => $meta['account_number'] ?? null,
                'shipments' => $this->extractShipments($text, $source),
            ]];
        }

        $invoices = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $section = (string) $parts[$i + 1];
            preg_match('/Invoice Date\s+([A-Z][a-z]{2} \d{1,2}, \d{4})/', $section, $d);
            preg_match('/Account Number\s+([0-9-]+)/', $section, $a);
            $invoices[] = [
                'number' => (string) $parts[$i],
                'invoice_date' => $d[1] ?? null,
                'account' => $a[1] ?? null,
                'shipments' => $this->extractShipments($section, $source),
            ];
        }

        return $invoices;
    }

    /**
     * Split a chunk of invoice text into shipment blocks and parse each.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractShipments(string $text, string $source): array
    {
        $ship = substr_count($text, 'Ship Date:');
        $pickup = substr_count($text, 'Pickup Date:');
        if ($ship > 0 || $pickup > 0) {
            $blocks = explode($pickup > $ship ? 'Pickup Date:' : 'Ship Date:', $text);
            array_shift($blocks);
        } else {
            $blocks = $this->splitByTotalMarker($text);
        }

        $shipments = [];
        foreach ($blocks as $block) {
            $shipment = $this->parseBlock($block, $source);
            if ($shipment !== null) {
                $shipments[] = $shipment;
            }
        }

        return $shipments;
    }

    /**
     * @return array{tracking_id: ?string, type: string, service_type: ?string, recipient: array<int, string>, charge_ledger: array<int, array{description: string, amount: float}>, total_charge: float}|null
     */
    protected function parseBlock(string $block, string $source): ?array
    {
        if (preg_match('/Total Charge\s+USD\s+\$([0-9,]+\.\d{2})/', $block, $m)) {
            $type = 'Ground';
        } elseif (preg_match('/Total Transportation Charges\s+USD\s+\$([0-9,]+\.\d{2})/', $block, $m)) {
            $type = 'Express';
        } else {
            return null;
        }
        $total = (float) str_replace(',', '', $m[1]);

        $start = strripos($block, 'Packages');
        $end = (int) strpos($block, $type === 'Express' ? 'Total Transportation Charges' : 'Total Charge', $start === false ? 0 : $start);
        $region = substr($block, $start === false ? 0 : $start, $end > 0 ? $end - (int) $start : null);

        // Try each strategy; accept the first ledger that reconciles to the Total.
        // Don't trust the total's label alone — some early invoices use
        // Express-style tab charges closed with a "Total Charge" (Ground) marker.
        $candidates = [
            $this->extractExpressCharges($region),
            $this->extractGroundCharges($region),
            $this->extractGroundPositional($region),
        ];

        foreach ($candidates as $ledger) {
            if ($ledger === []) {
                continue;
            }
            if (abs(array_sum(array_column($ledger, 'amount')) - $total) <= 0.02) {
                return [
                    'tracking_id' => $this->findTracking($block),
                    'type' => $type,
                    'ship_date' => preg_match('/^\s*([A-Z][a-z]{2} \d{1,2}, \d{4})/', $block, $d) ? $d[1] : null,
                    'service_type' => $this->extractServiceType($block, $type),
                    'recipient' => $this->extractRecipient($block),
                    'charge_ledger' => $ledger,
                    'total_charge' => $total,
                ];
            }
        }

        $this->logMismatchedBlock($this->findTracking($block), $type, $total, $source);

        return null;
    }

    /**
     * Fallback block split for early invoices with no date delimiter: break the
     * text so each segment ends with its own per-shipment Total marker.
     *
     * @return array<int, string>
     */
    protected function splitByTotalMarker(string $text): array
    {
        $parts = preg_split(
            '/((?:Total Charge|Total Transportation Charges)\s+USD\s+\$[0-9,]+\.\d{2})/',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (! is_array($parts)) {
            return [];
        }

        $blocks = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            // Charges segment + the Total marker it reconciles against.
            $blocks[] = $parts[$i].$parts[$i + 1];
        }

        return $blocks;
    }

    /**
     * Express: each line ends "<Label>\t<Amount>"; the label is the field
     * immediately before the amount — no dictionary needed.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function extractExpressCharges(string $region): array
    {
        $ledger = [];
        foreach (explode("\n", $region) as $line) {
            $fields = array_map('trim', explode("\t", $line));
            if (count($fields) < 2) {
                continue;
            }
            $amount = (string) array_pop($fields);
            if (! preg_match('/^-?[0-9,]+\.\d{2}$/', $amount)) {
                continue;
            }
            $label = (string) array_pop($fields);
            if ($label === '' || preg_match('/^-?[0-9,]+\.\d{2}$/', $label) || stripos($label, 'USD') !== false) {
                continue;
            }
            $ledger[] = ['description' => $label, 'amount' => (float) str_replace(',', '', $amount)];
        }

        return $ledger;
    }

    /**
     * Ground (dictionary): pair known labels (in order) with amounts (in order).
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function extractGroundCharges(string $region): array
    {
        [$labels, $amounts] = $this->tokenize($region);
        if (count($labels) === 0 || count($labels) !== count($amounts)) {
            return [];
        }

        $ledger = [];
        foreach ($labels as $i => $label) {
            $ledger[] = ['description' => $label, 'amount' => $amounts[$i]];
        }

        return $ledger;
    }

    /**
     * Ground (dictionary-free fallback): the charge amounts are a contiguous run
     * just before the Total; the labels are the non-amount lines right before
     * that run. Handles charge types not in the dictionary.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function extractGroundPositional(string $region): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $region)),
            static fn (string $l): bool => $l !== ''
        ));

        $isAmount = static fn (string $l): bool => (bool) preg_match('/^-?[0-9,]+\.\d{2}$/', $l);

        $firstAmountIdx = null;
        $amounts = [];
        foreach ($lines as $i => $line) {
            if ($isAmount($line)) {
                $firstAmountIdx ??= $i;
                $amounts[] = (float) str_replace(',', '', $line);
            }
        }
        if ($amounts === [] || $firstAmountIdx === null) {
            return [];
        }

        $before = array_values(array_filter(
            array_slice($lines, 0, $firstAmountIdx),
            static fn (string $l): bool => ! $isAmount($l)
        ));
        $labels = array_slice($before, -count($amounts));
        if (count($labels) !== count($amounts)) {
            return [];
        }

        $ledger = [];
        foreach ($labels as $i => $label) {
            $ledger[] = ['description' => $label, 'amount' => $amounts[$i]];
        }

        return $ledger;
    }

    /**
     * Collect known charge labels and decimal amounts in order (Ground dict path).
     *
     * @return array{0: array<int, string>, 1: array<int, float>}
     */
    protected function tokenize(string $region): array
    {
        $labels = [];
        $amounts = [];
        $len = strlen($region);
        $pos = 0;

        while ($pos < $len) {
            $char = $region[$pos];
            if ($char === ' ' || $char === "\n" || $char === "\t" || $char === "\r") {
                $pos++;

                continue;
            }

            $matched = null;
            foreach ($this->labels as $label) {
                if (strncasecmp(substr($region, $pos, strlen($label)), $label, strlen($label)) === 0) {
                    $matched = $label;
                    break;
                }
            }
            if ($matched !== null) {
                $labels[] = $matched;
                $pos += strlen($matched);

                continue;
            }

            if (preg_match('/^-?[0-9,]+\.\d{2}(?![0-9%])/', substr($region, $pos, 16), $a)) {
                $amounts[] = (float) str_replace(',', '', $a[0]);
                $pos += strlen($a[0]);

                continue;
            }

            $pos += max(1, strcspn(substr($region, $pos), " \n\t\r"));
        }

        return [$labels, $amounts];
    }

    /**
     * Parse the "FedEx Ground Address Correction" section: tracking + original
     * + corrected address (each ends in "ST ZIP US").
     *
     * @return array<int, array{tracking: string, original: string, corrected: string}>
     */
    public function extractCorrections(string $text): array
    {
        $pos = stripos($text, 'Ground Address Correction');
        if ($pos === false) {
            return [];
        }

        $out = [];
        foreach (preg_split('/Tracking ID:\s*/', substr($text, $pos)) as $block) {
            if (preg_match('/^(\d{10,22})\s+(.+?\s[A-Z]{2}\s+\d{5}(?:-\d{4})?\s+US)\s+(.+?\s[A-Z]{2}\s+\d{5}(?:-\d{4})?\s+US)/s', $block, $m)) {
                $out[] = [
                    'tracking' => $m[1],
                    'original' => trim(preg_replace('/\s+/', ' ', $m[2])),
                    'corrected' => trim(preg_replace('/\s+/', ' ', $m[3])),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    protected function extractRecipient(string $block): array
    {
        if (! preg_match('/Recipient\s*\n(.+?)(?=\n(?:Packages|Actual Weight|Fuel Surcharge|Transportation Charge|[A-Z][a-z]+ Surcharge|Total))/s', $block, $m)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $m[1])),
            static fn (string $l): bool => $l !== ''
        ));
    }

    protected function extractServiceType(string $block, string $type): ?string
    {
        if (preg_match('/\b(FedEx (?:International |Priority |Standard |First |Home |Ground )?[A-Z][A-Za-z ]+?)(?=\n)/', $block, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b((?:Ppd|Bill 3rd Party|Bill Recipient|Collect)[A-Za-z ,]*)/', $block, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @return array<string, ?string>
     */
    protected function extractMeta(string $text): array
    {
        preg_match('/Invoice Number\s+(\S+)/', $text, $inv);
        preg_match('/Account Number\s+(\S+)/', $text, $acct);
        preg_match('/Invoice Date\s+([A-Z][a-z]{2} \d{1,2}, \d{4})/', $text, $date);

        return [
            'invoice_number' => $inv[1] ?? null,
            'account_number' => $acct[1] ?? null,
            'invoice_date' => $date[1] ?? null,
        ];
    }

    protected function findTracking(string $block): ?string
    {
        if (preg_match('/\b(\d{12,22})\b/', $block, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function logMismatchedBlock(?string $tracking, string $type, float $expectedTotal, string $source): void
    {
        try {
            Log::warning('FedExInvoiceParser: block did not reconcile', [
                'source' => $source,
                'tracking' => $tracking,
                'type' => $type,
                'expected_total' => $expectedTotal,
            ]);
        } catch (\Throwable $e) {
            // No logger bound (e.g. standalone use) — skip silently.
        }
    }
}
