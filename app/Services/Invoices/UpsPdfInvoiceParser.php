<?php

namespace App\Services\Invoices;

/**
 * Parses UPS "Delivery Service Invoice" PDFs (Ricoh AFP2PDF text) for the
 * Address Corrections section, which lists per tracking number the address as
 * shipped ("Recorded:") and the carrier's on-file corrected address ("Corrected:").
 */
class UpsPdfInvoiceParser
{
    /** Street-type suffixes used to find where the street ends and the city begins. */
    private const SUFFIXES = [
        'ST', 'STREET', 'AVE', 'AVENUE', 'BLVD', 'BOULEVARD', 'DR', 'DRIVE', 'RD', 'ROAD',
        'LN', 'LANE', 'CT', 'COURT', 'PL', 'PLACE', 'CIR', 'CIRCLE', 'HWY', 'HIGHWAY',
        'PKWY', 'PARKWAY', 'WAY', 'TER', 'TERRACE', 'PLZ', 'PLAZA', 'SQ', 'LOOP', 'TRL',
        'TRAIL', 'PASS', 'RUN', 'ROW', 'PT', 'POINT', 'PIKE', 'EXPY', 'EXPRESSWAY', 'BND',
    ];

    private const DIRECTIONALS = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW'];

    /**
     * @return array{invoice_number: ?string, account_number: ?string, invoice_date: ?string, corrections: array<int, array<string, mixed>>}
     */
    public function parse(string $text): array
    {
        return [
            'invoice_number' => $this->firstMatch('/Invoice Number\s+(\S+)/', $text),
            'account_number' => $this->firstMatch('/Account Number\s+(\S+)/', $text),
            'invoice_date' => $this->firstMatch('/Invoice Date\s+([A-Z][a-z]+ \d{1,2}, \d{4})/', $text),
            'corrections' => $this->extractCorrections($text),
        ];
    }

    /**
     * Extract each Recorded -> Corrected pair with its tracking number.
     *
     * @return array<int, array{tracking_number: string, recorded: array<string,?string>, corrected: array<string,?string>}>
     */
    public function extractCorrections(string $text): array
    {
        // tracking … Recorded: <addr> Corrected: <addr> (stop at next tracking / page break / section end)
        $pattern = '/(1Z[0-9A-Z]{16})(?:(?!1Z[0-9A-Z]{16}).)*?\bRecorded:\s*(.+?)\s+Corrected:\s*(.+?)'
            .'(?=\s+1Z[0-9A-Z]{16}|\s*<I>|\s+Message Codes|\s+Total\b|$)/s';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $corrections = [];
        foreach ($matches as $m) {
            $corrections[] = [
                'tracking_number' => $m[1],
                'recorded' => $this->parseAddress($m[2]),
                'corrected' => $this->parseAddress($m[3]),
            ];
        }

        return $corrections;
    }

    /**
     * Best-effort split of a flat UPS address string into components.
     * Reliable for city/state/postal (end-anchored); name/street split is heuristic.
     *
     * @return array{raw: string, name: ?string, address_1: ?string, address_2: ?string, city: ?string, state: ?string, postal: ?string}
     */
    public function parseAddress(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        $result = ['raw' => $raw, 'name' => null, 'address_1' => null, 'address_2' => null, 'city' => null, 'state' => null, 'postal' => null];

        $work = $raw;

        // Secondary unit (Suite: Q, Ste 200, Apt 4, Unit 5). Deliberately excludes
        // "Fl"/"Floor"/"Rm" to avoid matching state codes like FL, and "St".
        if (preg_match('/\b(?:Suite|Ste|Apt|Apartment|Unit)\s*:?\s*([A-Za-z0-9\-]+)/i', $work, $m)) {
            $result['address_2'] = strtoupper(trim($m[0]));
            $work = trim(preg_replace('/\s+/', ' ', str_replace($m[0], ' ', $work)));
        }

        // Postal (zip or zip+4) at the end
        if (preg_match('/\b(\d{5})(?:-\d{4})?\s*$/', $work, $m)) {
            $result['postal'] = $m[1];
            $work = trim(substr($work, 0, strrpos($work, $m[0])));
        }

        // State: two-letter token now at the end
        if (preg_match('/\s([A-Za-z]{2})$/', $work, $m)) {
            $result['state'] = strtoupper($m[1]);
            $work = trim(substr($work, 0, strrpos($work, ' ')));
        }

        $tokens = $work === '' ? [] : explode(' ', $work);
        if (empty($tokens)) {
            return $result;
        }

        // House number = first numeric token whose next token is non-numeric
        // (skips leading store numbers like "PIZZA HUT 39499 9001 …").
        $houseIdx = null;
        foreach ($tokens as $i => $tok) {
            if (preg_match('/^\d+$/', $tok) && (! isset($tokens[$i + 1]) || ! preg_match('/^\d+$/', $tokens[$i + 1]))) {
                $houseIdx = $i;
                break;
            }
        }

        // Street suffix index (last suffix, plus any trailing directional/quadrant)
        $suffixIdx = null;
        foreach ($tokens as $i => $tok) {
            if (in_array(strtoupper(rtrim($tok, '.')), self::SUFFIXES, true)) {
                $suffixIdx = $i;
            }
        }

        if ($houseIdx !== null) {
            $result['name'] = $houseIdx > 0 ? implode(' ', array_slice($tokens, 0, $houseIdx)) : null;

            if ($suffixIdx !== null && $suffixIdx >= $houseIdx) {
                $streetEnd = $suffixIdx;
                if (isset($tokens[$suffixIdx + 1]) && in_array(strtoupper($tokens[$suffixIdx + 1]), self::DIRECTIONALS, true)) {
                    $streetEnd = $suffixIdx + 1;
                }
                $result['address_1'] = implode(' ', array_slice($tokens, $houseIdx, $streetEnd - $houseIdx + 1));
                $cityTokens = array_slice($tokens, $streetEnd + 1);
                $result['city'] = $cityTokens ? implode(' ', $cityTokens) : null;
            } else {
                // No recognizable suffix (e.g. TX "FM" roads): treat last token as city.
                $cityToken = array_pop($tokens);
                $result['city'] = $cityToken;
                $result['address_1'] = implode(' ', array_slice($tokens, $houseIdx));
            }
        } else {
            // No house number found: assume last token is city, rest is name/street.
            $cityToken = array_pop($tokens);
            $result['city'] = $cityToken ?: null;
            $result['address_1'] = $tokens ? implode(' ', $tokens) : null;
        }

        foreach (['name', 'address_1', 'address_2', 'city'] as $field) {
            if ($result[$field] !== null) {
                $trimmed = trim(preg_replace('/\s+/', ' ', $result[$field]));
                $result[$field] = $trimmed === '' ? null : $trimmed;
            }
        }

        return $result;
    }

    private function firstMatch(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) ? $m[1] : null;
    }
}
