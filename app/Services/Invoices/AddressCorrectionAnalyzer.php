<?php

namespace App\Services\Invoices;

/**
 * Grades an address correction by how much actually changed.
 *
 * Two signals:
 *  - severity_score: Levenshtein distance AFTER normalization, so abbreviation/
 *    punctuation/whitespace differences (e.g. "ST" vs "STREET") collapse to 0.
 *  - change_type: the most delivery-significant component that changed (zip,
 *    street number, street name, suite, city, state) — or formatting_only.
 *
 * A formatting_only correction that carried a fee is, by definition, frivolous:
 * the carrier charged us to restate the same deliverable address.
 */
class AddressCorrectionAnalyzer
{
    /**
     * USPS-style abbreviations canonicalized to a single form (long -> short),
     * applied token-wise so "STREET" and "ST" compare equal.
     *
     * @var array<string, string>
     */
    private const ABBREV = [
        'STREET' => 'ST', 'AVENUE' => 'AVE', 'BOULEVARD' => 'BLVD', 'ROAD' => 'RD',
        'DRIVE' => 'DR', 'LANE' => 'LN', 'COURT' => 'CT', 'PLACE' => 'PL',
        'CIRCLE' => 'CIR', 'HIGHWAY' => 'HWY', 'PARKWAY' => 'PKWY', 'TERRACE' => 'TER',
        'TRAIL' => 'TRL', 'SQUARE' => 'SQ', 'PIKE' => 'PIKE',
        'SUITE' => 'STE', 'APARTMENT' => 'APT', 'BUILDING' => 'BLDG', 'FLOOR' => 'FL',
        'DEPARTMENT' => 'DEPT', 'ROOM' => 'RM',
        'NORTH' => 'N', 'SOUTH' => 'S', 'EAST' => 'E', 'WEST' => 'W',
        'NORTHEAST' => 'NE', 'NORTHWEST' => 'NW', 'SOUTHEAST' => 'SE', 'SOUTHWEST' => 'SW',
    ];

    private const SUITE_TOKENS = ['STE', 'APT', 'UNIT', 'BLDG', 'FL', 'RM', 'DEPT', 'LOT', 'SPC'];

    /**
     * @param  array{address_1?: ?string, address_2?: ?string, city?: ?string, state?: ?string, postal?: ?string}  $original
     * @param  array{address_1?: ?string, address_2?: ?string, city?: ?string, state?: ?string, postal?: ?string}  $corrected
     * @return array{severity_score: int, severity_category: string, change_type: string}
     */
    public function analyze(array $original, array $corrected): array
    {
        $normOriginal = $this->normalizeFull($original);
        $normCorrected = $this->normalizeFull($corrected);

        // levenshtein() caps at 255 chars per argument.
        $score = levenshtein(substr($normOriginal, 0, 255), substr($normCorrected, 0, 255));

        $category = match (true) {
            $score === 0 => 'formatting_only',
            $score <= 3 => 'micro',
            $score <= 10 => 'minor',
            default => 'major',
        };

        return [
            'severity_score' => $score,
            'severity_category' => $category,
            'change_type' => $this->changeType($original, $corrected, $score),
        ];
    }

    /**
     * @param  array<string, ?string>  $a
     */
    private function normalizeFull(array $a): string
    {
        return $this->normalize(implode(' ', array_filter([
            $a['address_1'] ?? '', $a['address_2'] ?? '', $a['city'] ?? '',
            $a['state'] ?? '', $a['postal'] ?? '',
        ])));
    }

    public function normalize(string $value): string
    {
        $value = strtoupper($value);
        $value = (string) preg_replace('/[.,#]/', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', trim($value));

        $tokens = array_filter(explode(' ', $value), static fn (string $t): bool => $t !== '');
        $tokens = array_map(static fn (string $t): string => self::ABBREV[$t] ?? $t, $tokens);

        return implode(' ', $tokens);
    }

    /**
     * @param  array<string, ?string>  $o
     * @param  array<string, ?string>  $c
     */
    private function changeType(array $o, array $c, int $score): string
    {
        if ($score === 0) {
            return 'formatting_only';
        }

        $zipO = $this->zip($o['postal'] ?? '');
        $zipC = $this->zip($c['postal'] ?? '');
        if ($zipO !== '' && $zipC !== '' && $zipO !== $zipC) {
            return 'zip_changed';
        }

        $stO = strtoupper(trim((string) ($o['state'] ?? '')));
        $stC = strtoupper(trim((string) ($c['state'] ?? '')));
        if ($stO !== '' && $stC !== '' && $stO !== $stC) {
            return 'state_changed';
        }

        $numO = $this->streetNumber($o['address_1'] ?? '');
        $numC = $this->streetNumber($c['address_1'] ?? '');
        if ($numO !== '' && $numC !== '' && $numO !== $numC) {
            return 'street_number_changed';
        }

        if ($this->streetName($o['address_1'] ?? '') !== $this->streetName($c['address_1'] ?? '')) {
            return 'street_renamed';
        }

        if ($this->suite($o) !== $this->suite($c)) {
            return 'suite_changed';
        }

        if ($this->normalize((string) ($o['city'] ?? '')) !== $this->normalize((string) ($c['city'] ?? ''))) {
            return 'city_changed';
        }

        return 'other';
    }

    private function zip(?string $postal): string
    {
        return substr((string) preg_replace('/\D/', '', (string) $postal), 0, 5);
    }

    private function streetNumber(?string $address): string
    {
        return preg_match('/^\s*(\d+)/', (string) $address, $m) ? $m[1] : '';
    }

    private function streetName(?string $address): string
    {
        // Normalized street, with the leading number and any suite portion removed.
        $norm = $this->normalize((string) preg_replace('/^\s*\d+\s*/', '', (string) $address));
        $tokens = explode(' ', $norm);
        $out = [];
        foreach ($tokens as $token) {
            if (in_array($token, self::SUITE_TOKENS, true)) {
                break; // street name ends where the suite designator begins
            }
            $out[] = $token;
        }

        return implode(' ', $out);
    }

    /**
     * @param  array<string, ?string>  $a
     */
    private function suite(array $a): string
    {
        $haystack = $this->normalize(($a['address_1'] ?? '').' '.($a['address_2'] ?? ''));
        $tokens = explode(' ', $haystack);
        foreach ($tokens as $i => $token) {
            if (in_array($token, self::SUITE_TOKENS, true)) {
                return $token.' '.($tokens[$i + 1] ?? '');
            }
        }

        return '';
    }
}
