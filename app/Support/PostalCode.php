<?php

namespace App\Support;

class PostalCode
{
    /**
     * Repair a US ZIP that Excel truncated by dropping leading zeros (7001 → 07001, 501 → 00501),
     * preserving any ZIP+4 suffix (7001-1234 → 07001-1234). Returns non-US, non-numeric, blank, and
     * already-5+ values untouched.
     */
    public static function normalizeUs(?string $zip, ?string $country = null): ?string
    {
        if ($zip === null) {
            return null;
        }

        $trimmed = trim($zip);

        if ($trimmed === '' || ! self::isUnitedStates($country)) {
            return $zip;
        }

        // Pad a 1-4 digit base to 5, keeping an optional "-1234"/" 1234" ZIP+4 suffix. Anything else
        // (5+ digits, alphanumeric foreign codes) has no 1-4 digit base and falls through unchanged.
        if (preg_match('/^(\d{1,4})([\s-]\d{1,4})?$/', $trimmed, $matches)) {
            return str_pad($matches[1], 5, '0', STR_PAD_LEFT).($matches[2] ?? '');
        }

        return $zip;
    }

    private static function isUnitedStates(?string $country): bool
    {
        $code = strtoupper(trim((string) $country));

        return $code === '' || in_array($code, [
            'US', 'USA', 'U.S.', 'U.S.A.', 'UNITED STATES', 'UNITED STATES OF AMERICA', '840',
        ], true);
    }
}
