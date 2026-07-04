<?php

namespace App\Services\Invoices;

/**
 * Parses UPS "Delivery Service Invoice" PDFs (flattened smalot text) into shipments,
 * charges, DIM-audit data and message codes — the detail the older UpsPdfInvoiceParser
 * (address-corrections only) never touched.
 *
 * The extractor output is a FLAT string with no reliable newlines, so parsing is
 * marker-driven: sections are carved by their column-header runs (anchored AFTER the
 * pages 2–3 incentive summary that reuses the same phrases), then each section's text
 * is split into per-shipment blocks on the 1Z tracking boundary.
 *
 * Key money rules (validated to the cent against a 1,394-page invoice):
 *  - Every charge line ends in Published, Incentive(neg), Billed. BILLED is payable —
 *    EXCEPT the Shipping Charge Corrections section, whose payable is the 4th
 *    "Adjustment Amount" column.
 *  - Blocks may have no Total line, no amounts at all (bare CWT siblings), or only a
 *    $0 third-party pair. Nothing about the block shape is mandatory.
 */
class UpsPdfChargeParser
{
    /** A money token: -1,234.56 style. Two decimals distinguishes it from weights (1 dp) and ints. */
    private const AMT = '-?\d{1,3}(?:,\d{3})*\.\d{2}';

    private const TRACK = '1Z[0-9A-Z]{16}';

    /** Invoice month (1–12), used to roll pickup-date years back across a year boundary. */
    private ?int $invoiceMonth = null;

    /**
     * Section definitions in printed order. `header` is the column-header run that marks
     * the section's detail start; `intCols` is how many integer columns sit between the
     * service name and the amount run on a base line.
     *
     * @var array<int, array{key: string, header: string, intCols: int}>
     */
    private const SECTIONS = [
        ['key' => 'outbound', 'header' => 'Outbound Shipping API Pickup Date Tracking Number Service ZIP Code Zone Weight Published Charge Incentive Credit Billed Charge', 'intCols' => 3],
        ['key' => 'inbound', 'header' => 'Inbound Collect Pickup Date Pickup Record Entry Tracking Number Service ZIP Code Zone Weight Published Charge Incentive Credit Billed Charge', 'intCols' => 3],
        ['key' => 'address_correction', 'header' => 'Address Corrections Tracking Number Service Number of Packages Published Charge Incentive Credit Billed Charge', 'intCols' => 1],
        ['key' => 'packages_not_billed', 'header' => 'Packages Delivered but not Previously Billed Delivery Date Tracking Number Service Zone Weight Published Charge Incentive Credit Billed Charge', 'intCols' => 2],
        ['key' => 'shipping_charge_correction', 'header' => 'Shipping Charge Corrections', 'intCols' => 3],
        ['key' => 'adjustments', 'header' => 'Adjustments Explanation Number of Packages Published Charge Incentive Credit Billed Charge', 'intCols' => 0],
        ['key' => 'service', 'header' => 'Service Charges Date Explanation Published Charge Incentive Credit Billed Charge', 'intCols' => 0],
    ];

    /**
     * @return array{
     *   invoice_number: ?string, account_number: ?string, invoice_date: ?string,
     *   message_codes: array<string,string>,
     *   shipments: array<int, array<string, mixed>>,
     *   account_charges: array<int, array<string, mixed>>,
     *   reconciliation: array{parsed_total: float, sections: array<string, float>}
     * }
     */
    public function parse(string $text): array
    {
        $year = null;
        $date = null;
        if (preg_match('/Invoice Date\s+([A-Z][a-z]+ \d{1,2}, \d{4})/', $text, $m)) {
            $date = date('Y-m-d', strtotime($m[1]));
            $year = (int) substr($date, 0, 4);
            $this->invoiceMonth = (int) substr($date, 5, 2);
        }

        $clean = $this->stripFurniture($text);

        $result = [
            'invoice_number' => $this->firstMatch('/Invoice Number\s+(\S+)/', $text),
            'account_number' => $this->firstMatch('/Account Number\s+(\S+)/', $text),
            'invoice_date' => $date,
            'message_codes' => [],
            'shipments' => [],
            'account_charges' => [],
            'reconciliation' => ['parsed_total' => 0.0, 'sections' => []],
        ];

        foreach ($this->carveSections($clean) as $section) {
            [$shipments, $accountCharges] = $this->parseSection($section['key'], $section['intCols'], $section['text'], $year);
            $result['shipments'] = array_merge($result['shipments'], $shipments);
            $result['account_charges'] = array_merge($result['account_charges'], $accountCharges);

            $sum = 0.0;
            foreach ($shipments as $s) {
                $sum += array_sum(array_column($s['charges'], 'amount'));
            }
            foreach ($accountCharges as $c) {
                $sum += $c['amount'];
            }
            $result['reconciliation']['sections'][$section['key']] = round($sum, 2);
            $result['reconciliation']['parsed_total'] += $sum;
        }

        $result['reconciliation']['parsed_total'] = round($result['reconciliation']['parsed_total'], 2);

        // Resolve the glossary using the codes shipments actually reference — splitting on
        // a known code set avoids mis-slicing multi-word definitions.
        $used = [];
        foreach ($result['shipments'] as $s) {
            foreach ($s['message_codes'] as $c) {
                $used[$c] = true;
            }
        }
        $result['message_codes'] = $this->parseGlossary($clean, array_keys($used));

        return $result;
    }

    /**
     * Remove repeating page furniture so page-straddling shipment blocks join cleanly and
     * subtotal lines never parse as charges.
     */
    private function stripFurniture(string $text): string
    {
        $patterns = [
            // Per-page machine + banner line.
            '/<I>\d+<\/I>\s*Delivery Service Invoice Invoice Date [A-Z][a-z]+ \d{1,2}, \d{4} Invoice Number \S+ Account Number \S+(?: Control ID \S+)? Page [\d,]+ of [\d,]+/',
            // Repeated per-page column headers — ONLY the "(continued)" ones, so each
            // section's FIRST header survives for carveSections() to anchor on.
            '/(?:Outbound Shipping API|Inbound Collect) \(continued\) Pickup Date(?: Pickup Record Entry)? Tracking Number Service ZIP Code Zone Weight Published Charge Incentive Credit Billed Charge/',
            // SCC avoid-charges blurb.
            '/Learn how to avoid future shipping charge corrections\.\s*Visit www\.ups\.com\/avoidcharges\./',
            // Internet-ID / Shipper subtotal furniture (carries amounts — must go).
            '/Total for Internet-ID\s*:\s*\S+(?:\s+'.self::AMT.')+/',
            '/Total for Shipper\s*:\s*\S+(?:\s+'.self::AMT.')+/',
        ];

        return (string) preg_replace($patterns, ' ', $text);
    }

    /**
     * Locate each present section by its header (forward-only, after the incentive
     * summary) and return its text span up to the next section.
     *
     * @return array<int, array{key: string, intCols: int, text: string}>
     */
    private function carveSections(string $text): array
    {
        // Anchor: the first real Outbound column-header run (summary pages don't contain it).
        $anchor = strpos($text, self::SECTIONS[0]['header']);
        if ($anchor === false) {
            $anchor = 0;
        }

        $found = [];
        foreach (self::SECTIONS as $def) {
            $pos = strpos($text, $def['header'], $def['key'] === 'outbound' ? $anchor : 0);
            if ($pos !== false && $pos >= $anchor) {
                $found[] = ['key' => $def['key'], 'intCols' => $def['intCols'], 'pos' => $pos, 'headerLen' => strlen($def['header'])];
            }
        }

        usort($found, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        $sections = [];
        foreach ($found as $i => $s) {
            $start = $s['pos'] + $s['headerLen'];
            $end = $found[$i + 1]['pos'] ?? strlen($text);
            $sections[] = ['key' => $s['key'], 'intCols' => $s['intCols'], 'text' => substr($text, $start, $end - $start)];
        }

        return $sections;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseSection(string $key, int $intCols, string $text, ?int $year): array
    {
        if ($key === 'service' || $key === 'adjustments') {
            return [[], $this->parseAccountCharges($key, $text)];
        }

        $shipments = [];
        // Split into per-shipment blocks on the tracking boundary. The pickup/delivery
        // date (MM/DD) precedes its tracking, so it lands at the END of the previous part —
        // carry it forward to the next shipment.
        $parts = preg_split('/(?='.self::TRACK.')/', $text);
        $pendingDate = $this->trailingDate($parts[0] ?? '');
        foreach ($parts as $part) {
            if (! preg_match('/^('.self::TRACK.')/', $part, $tm)) {
                continue;
            }
            $shipment = $this->parseShipmentBlock($key, $intCols, $tm[1], $part, $year);
            $shipment['ship_date'] = $pendingDate !== null ? $this->composeDate($pendingDate, $year) : null;
            $pendingDate = $this->trailingDate($part);
            $shipments[] = $shipment;
        }

        return [$shipments, []];
    }

    /**
     * Parse one shipment block into its shipment attributes + charge rows.
     *
     * @return array<string, mixed>
     */
    private function parseShipmentBlock(string $section, int $intCols, string $tracking, string $block, ?int $year): array
    {
        $shipment = [
            'section' => $section,
            'tracking_number' => $tracking,
            'service' => null,
            'zip' => null,
            'zone' => null,
            'weight' => null,
            'ship_date' => null,
            'customer_dims' => null,
            'audited_dims' => null,
            'customer_weight' => null,
            'billed_weight' => null,
            'message_codes' => [],
            'sender' => null,
            'receiver' => null,
            'third_party' => null,
            'is_third_party' => false,
            'printed_total' => null,
            'charges' => [],
        ];

        // Pickup/Delivery date sits just BEFORE the tracking (MM/DD).
        if (preg_match('#(\d{2}/\d{2})\s*$#', substr($block, -0, 0))) {
            // handled by caller-provided prefix below
        }

        // The charge region ends where the metadata tail begins.
        $tailMarkers = ['1st ref:', 'Sender :', 'Sender:', 'Receiver:', 'Recorded:', 'Third Party:', 'Message Codes'];
        $tailPos = strlen($block);
        foreach ($tailMarkers as $mk) {
            $p = strpos($block, $mk);
            if ($p !== false && $p < $tailPos) {
                $tailPos = $p;
            }
        }
        $head = substr($block, strlen($tracking), $tailPos - strlen($tracking));
        $tail = substr($block, $tailPos);

        // --- metadata from head + tail ---
        if (preg_match('/Audited Dimensions\s*=\s*([\d\sx]+?)\s*in\b/', $block, $m)) {
            $shipment['audited_dims'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        if (preg_match('/Customer Entered Dimensions\s*=\s*([\d\sx]+?)\s*in\b/', $block, $m)) {
            $shipment['customer_dims'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        if (preg_match('/Customer Weight\s+([\d.]+)/', $block, $m)) {
            $shipment['customer_weight'] = (float) $m[1];
        }
        // Codes are 1–3 char tokens, space-separated ("bg dd"); a longer following word
        // (e.g. "Package") is not a code and must not be swallowed.
        if (preg_match('/Message Codes ?: ?((?:[A-Za-z0-9]{1,3}(?:\s+|$))+)/', $block, $m)) {
            $shipment['message_codes'] = array_values(array_filter(
                preg_split('/\s+/', trim($m[1])),
                fn ($c) => $c !== '' && strlen($c) <= 3,
            ));
        }
        if (preg_match('/Sender ?:\s*(.+?)(?=\s+Receiver:|\s+Message Codes|\s+Third Party:|$)/s', $tail, $m)) {
            $shipment['sender'] = $this->cleanAddress($m[1]);
        }
        if (preg_match('/Receiver:\s*(.+?)(?=\s+Sender ?:|\s+Message Codes|\s+Third Party:|$)/s', $tail, $m)) {
            $shipment['receiver'] = $this->cleanAddress($m[1]);
        }
        if (preg_match('/Third Party:\s*(.+?)(?=\s+Sender ?:|\s+Receiver:|\s+Message Codes|$)/s', $tail, $m)) {
            $shipment['third_party'] = $this->cleanAddress($m[1]);
        }

        // Remove info phrases from head so they don't pollute charge tokenizing.
        $headClean = preg_replace([
            '/(?:Audited|Customer Entered) Dimensions\s*=\s*[\d\sx]+?\s*in\b/',
            '/Customer Weight\s+[\d.]+/',
            '/Customer Entered Dimensions = [\d\sx]+/',
        ], ' ', $head);

        if ($section === 'shipping_charge_correction') {
            $this->parseSccBlock($shipment, $headClean);
        } else {
            $this->parseStandardBlock($shipment, $intCols, $headClean);
        }

        // Robust third-party detection: a "Third Party:" bill-to line OR a service that
        // carries "Third Party" — not just the $0 heuristic (a third-party shipment CAN
        // still hit us with a chargeback fee).
        $shipment['is_third_party'] = $shipment['third_party'] !== null
            || ($shipment['service'] !== null && stripos($shipment['service'], 'Third Party') !== false);

        return $shipment;
    }

    /**
     * Standard sections: one base line (service + intCols integers + Pub/Inc/Billed),
     * then surcharge lines (desc + Pub/Inc/Billed), then an optional Total.
     *
     * @param  array<string, mixed>  $shipment
     */
    private function parseStandardBlock(array &$shipment, int $intCols, string $head): void
    {
        // Capture then drop the block Total line (used only to validate our charge sum).
        $amt = self::AMT;
        if (preg_match('/\bTotal\s+'.$amt.'\s+'.$amt.'\s+('.$amt.')/', $head, $tm)) {
            $shipment['printed_total'] = (float) str_replace(',', '', $tm[1]);
        }
        $head = preg_replace('/\bTotal\s+(?:'.$amt.'\s*){1,3}/', ' ', $head);

        // Base line: leading service words, then intCols integer/decimal columns, then amounts.
        $baseRe = '/^\s*(.+?)\s+((?:\d[\d.]*\s+){'.$intCols.'})('.$amt.'(?:\s+'.$amt.'){0,2})/';
        if ($intCols > 0 && preg_match($baseRe, $head, $bm)) {
            $shipment['service'] = trim($bm[1]);
            $cols = preg_split('/\s+/', trim($bm[2]));
            $this->assignBaseColumns($shipment, $intCols, $cols);
            $amounts = preg_split('/\s+/', trim($bm[3]));
            $this->addCharge($shipment, $shipment['service'], $amounts);
            $rest = substr($head, strlen($bm[0]));
        } else {
            // intCols == 0 base line, or no integer columns present: first "<words> <amts>".
            $rest = $head;
        }

        // Surcharge lines: <desc words> <1-3 amounts>. Description may carry digits/parens
        // ("Additional Handling - Weight (4)"), so the class is broad; lazy match + the
        // required trailing amount run keeps it from swallowing the amounts.
        if (preg_match_all('/([A-Za-z][A-Za-z0-9 \-\/&.()]*?)\s+('.$amt.'(?:\s+'.$amt.'){0,2})(?=\s|$)/', $rest ?? '', $ms, PREG_SET_ORDER)) {
            foreach ($ms as $sm) {
                $desc = trim($sm[1]);
                if ($desc === '' || strcasecmp($desc, 'Total') === 0) {
                    continue;
                }
                $this->addCharge($shipment, $desc, preg_split('/\s+/', trim($sm[2])));
            }
        }
    }

    /**
     * Shipping Charge Corrections: two base rating lines (original + corrected), then a
     * surcharge line carrying a 4th "Adjustment Amount" token = the payable. The corrected
     * weight is a decimal; capture it as billed_weight. Payable = sum of 4th tokens.
     *
     * @param  array<string, mixed>  $shipment
     */
    private function parseSccBlock(array &$shipment, string $head): void
    {
        $amt = self::AMT;

        // Capture ZIP/Zone/(corrected)Weight from the base rating lines.
        if (preg_match_all('/(?:[A-Za-z][A-Za-z \-\/]*?)\s+(\d{5})\s+(\d{1,3})\s+([\d.]+)\s+'.$amt.'/', $head, $bm, PREG_SET_ORDER)) {
            $first = $bm[0];
            $shipment['zip'] = $first[1];
            $shipment['zone'] = $first[2];
            $last = end($bm);
            // corrected line's weight (may be decimal) is the billed/audited weight
            $shipment['billed_weight'] = (float) $last[3];
            $shipment['weight'] = (float) $first[3];
            $shipment['service'] = 'Shipping Charge Correction';
        }

        // Payable = the 4th amount column wherever a 4-token run appears.
        $adjustment = 0.0;
        if (preg_match_all('/(?:'.$amt.'\s+){3}('.$amt.')(?=\s|$)/', $head, $am)) {
            foreach ($am[1] as $a) {
                $adjustment += (float) str_replace(',', '', $a);
            }
        }
        if ($adjustment !== 0.0) {
            $shipment['charges'][] = [
                'description' => 'Shipping Charge Correction',
                'published' => null,
                'incentive' => null,
                'billed' => round($adjustment, 2),
                'amount' => round($adjustment, 2),
            ];
        }
    }

    /**
     * Account-level charges (Service Charges, Adjustments audit fee) — no tracking.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseAccountCharges(string $key, string $text): array
    {
        $amt = self::AMT;
        $charges = [];

        // Stop at the section total to avoid double counting.
        $text = preg_split('/Total (?:Service Charges|Adjustments)/', $text)[0];

        if ($key === 'service') {
            // "<Explanation words> <Pub> <Inc> <Billed>" — capture Billed (last).
            if (preg_match_all('/([A-Za-z][A-Za-z .\/&-]*?)\s+'.$amt.'\s+'.$amt.'\s+('.$amt.')/', $text, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    $desc = trim($m[1]);
                    if ($desc === '' || stripos($desc, 'Total') !== false) {
                        continue;
                    }
                    $charges[] = $this->accountCharge($key, $desc, (float) str_replace(',', '', $m[2]));
                }
            }
        } else {
            // Adjustments audit fee: free text ending in "<Pub> <Billed>" — capture Billed.
            if (preg_match_all('/([A-Z][A-Za-z0-9 .%,\/-]*?)\s+'.$amt.'\s+('.$amt.')/', $text, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    $charges[] = $this->accountCharge($key, trim(preg_replace('/\s+/', ' ', $m[1])), (float) str_replace(',', '', $m[2]));
                }
            }
        }

        return $charges;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountCharge(string $section, string $desc, float $billed): array
    {
        return ['section' => $section, 'description' => $desc, 'amount' => round($billed, 2)];
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @param  array<int, string>  $cols
     */
    private function assignBaseColumns(array &$shipment, int $intCols, array $cols): void
    {
        // Right-aligned: weight is last, zone before, zip before that.
        if ($intCols >= 1) {
            $shipment['weight'] = (float) end($cols);
        }
        if ($intCols >= 2) {
            $shipment['zone'] = $cols[count($cols) - 2];
        }
        if ($intCols >= 3) {
            $shipment['zip'] = $cols[count($cols) - 3];
        }
    }

    /**
     * Add a charge row. amount = Billed (last token); pub/inc kept for audit.
     *
     * @param  array<string, mixed>  $shipment
     * @param  array<int, string>  $amounts
     */
    private function addCharge(array &$shipment, string $description, array $amounts): void
    {
        $nums = array_map(fn ($a) => (float) str_replace(',', '', $a), $amounts);
        $billed = end($nums);
        if ($billed === 0.0) {
            return; // $0 lines (e.g. third-party billed) get a shipment row but no charge.
        }
        $shipment['charges'][] = [
            'description' => $description,
            'published' => $nums[0] ?? null,
            'incentive' => count($nums) === 3 ? $nums[1] : null,
            'billed' => $billed,
            'amount' => round($billed, 2),
        ];
    }

    /**
     * Parse the per-invoice "Invoice Messaging" glossary into code => meaning, splitting on
     * the codes the shipments actually reference (passed in) rather than guessing token
     * boundaries — the definitions are multi-word and would otherwise mis-slice.
     *
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    private function parseGlossary(string $text, array $codes): array
    {
        $p = strpos($text, 'Invoice Messaging Code Message');
        if ($p === false || $codes === []) {
            return [];
        }
        $glossary = substr($text, $p + strlen('Invoice Messaging Code Message'), 2000);

        // Locate each code as a standalone token (space before, capitalized word after).
        $positions = [];
        foreach ($codes as $code) {
            if (preg_match('/(?<=\s)'.preg_quote($code, '/').'(?=\s+[A-Z])/', ' '.$glossary, $m, PREG_OFFSET_CAPTURE)) {
                $positions[$code] = $m[0][1] - 1; // undo the space we prepended
            }
        }
        asort($positions);

        $ordered = array_keys($positions);
        $meanings = [];
        foreach ($ordered as $i => $code) {
            $start = $positions[$code] + strlen($code);
            $end = isset($ordered[$i + 1]) ? $positions[$ordered[$i + 1]] : strlen($glossary);
            $meanings[$code] = trim(substr($glossary, $start, $end - $start), " .\t");
        }

        return $meanings;
    }

    private function firstMatch(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) ? $m[1] : null;
    }

    /** Normalize an address string: collapse whitespace, drop a trailing pickup date + furniture. */
    private function cleanAddress(string $raw): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', $raw));
        $s = preg_replace('#\s*\d{2}/\d{2}\s*$#', '', $s);
        $s = preg_replace('/\s*Shaded area denotes.*$/i', '', $s);

        return $s === '' ? null : trim($s);
    }

    /** The trailing MM/DD of a block = the NEXT shipment's pickup date. */
    private function trailingDate(string $part): ?string
    {
        return preg_match('#(\d{2}/\d{2})\s*$#', rtrim($part), $m) ? $m[1] : null;
    }

    /**
     * Compose an MM/DD pickup date into Y-m-d using the invoice year, rolling back a year
     * when the pickup month is after the invoice month (Dec pickups on a Jan invoice).
     */
    private function composeDate(string $mmdd, ?int $year): ?string
    {
        if ($year === null || ! preg_match('#^(\d{2})/(\d{2})$#', $mmdd, $m)) {
            return null;
        }
        $useYear = $year;
        // Invoice month unknown here; a pickup in Nov/Dec on an early-year invoice rolls back.
        if ((int) $m[1] >= 11 && $this->invoiceMonth !== null && $this->invoiceMonth <= 2) {
            $useYear = $year - 1;
        }

        return sprintf('%04d-%02d-%02d', $useYear, (int) $m[1], (int) $m[2]);
    }
}
